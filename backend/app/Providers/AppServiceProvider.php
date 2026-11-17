<?php

namespace App\Providers;

use App\Services\Erp\Contracts\GraduateSource;
use App\Services\Erp\DatabaseGraduateSource;
use App\Services\Erp\GraduateMapper;
use App\Services\Erp\NullGraduateSource;
use App\Services\Erp\RestGraduateSource;
use App\Services\Messaging\CloudWhatsAppGateway;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Contracts\WhatsAppGateway;
use App\Services\Messaging\LogSmsGateway;
use App\Services\Messaging\LogWhatsAppGateway;
use App\Services\Messaging\MtnSmsGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Providers sit behind interfaces (spec sections 7.2, 7.3). An unknown driver name is an error,
        // not a silent fallback: a typo must never quietly turn real messages into log lines.
        $this->app->bind(SmsGateway::class, fn () => match ($driver = config('sunates.messaging.sms_driver')) {
            'log' => new LogSmsGateway,
            'mtn' => new MtnSmsGateway,
            default => throw new InvalidArgumentException("Unknown SMS_DRIVER '{$driver}' (use log or mtn)."),
        });

        $this->app->bind(WhatsAppGateway::class, fn () => match ($driver = config('sunates.messaging.whatsapp_driver')) {
            'log' => new LogWhatsAppGateway,
            'cloud' => new CloudWhatsAppGateway,
            default => throw new InvalidArgumentException("Unknown WHATSAPP_DRIVER '{$driver}' (use log or cloud)."),
        });

        // SorotiUniERP (spec section 7.1): one interface, so going live is a setting, not a code change.
        $this->app->bind(GraduateMapper::class, fn () => GraduateMapper::fromConfig());

        $this->app->bind(GraduateSource::class, fn ($app) => match ($driver = config('sunates.erp.driver')) {
            'none' => new NullGraduateSource,
            'rest' => new RestGraduateSource,
            'database' => new DatabaseGraduateSource($app->make(GraduateMapper::class)),
            default => throw new InvalidArgumentException("Unknown ERP_DRIVER '{$driver}' (use none, rest or database)."),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Spec section 9: HTTPS throughout. In production every generated link (survey, stop, reset,
        // verification) is https even if TLS ends at Nginx in front of PHP-FPM.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Password-reset emails link to the alumni app's "choose a new password" screen, not a Laravel page.
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('sunates.pwa_url').'/reset-password?'
            .http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Sign-in attempts (alumni app and staff console). Per identifier + IP, so one address cannot
        // lock out a real user elsewhere; and per IP alone, so one address cannot try a common password
        // against many accounts. 20 a minute leaves room for several staff behind one campus NAT.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('login-ip|'.$request->ip()),
        ]);

        // Registration probes the Registrar records, so keep it tight per IP.
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perDay(30)->by($request->ip()),
        ]);

        // Typing back a registration code. Each code also allows only a few wrong tries, so this limit is
        // about one address working through many registrations at once.
        RateLimiter::for('register-code', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perDay(60)->by($request->ip()),
        ]);

        // "Forgot my password" sends an email, and choosing a new password tries a token: keep both slow.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perDay(30)->by($request->ip()),
        ]);

        // Survey links are capability URLs (the token is the credential); throttle guessing and abuse.
        RateLimiter::for('survey', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Provider callbacks: generous, but not unbounded.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // The employer verification lookup has no sign-in, so it is the one door an outsider can knock
        // on as often as they like (spec section 9). The limit lets an HR office check a batch of
        // graduates but makes guessing names in bulk slow and conspicuous in the log.
        RateLimiter::for('verification', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
            Limit::perDay(200)->by($request->ip()),
        ]);

        // "Ask the Registrar" sends a person an email's worth of work, so it is limited much harder.
        RateLimiter::for('verification-enquiry', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
            Limit::perDay(15)->by($request->ip()),
        ]);
    }
}

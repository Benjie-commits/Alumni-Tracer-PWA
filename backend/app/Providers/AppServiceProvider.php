<?php

namespace App\Providers;

use App\Services\Messaging\CloudWhatsAppGateway;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Contracts\WhatsAppGateway;
use App\Services\Messaging\LogSmsGateway;
use App\Services\Messaging\LogWhatsAppGateway;
use App\Services\Messaging\MtnSmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Sign-in attempts, keyed by identifier + IP so one address cannot lock out a real user elsewhere.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));

        // Registration probes the Registrar records, so keep it tight per IP.
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perDay(30)->by($request->ip()),
        ]);

        // Survey links are capability URLs (the token is the credential); throttle guessing and abuse.
        RateLimiter::for('survey', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Provider callbacks: generous, but not unbounded.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}

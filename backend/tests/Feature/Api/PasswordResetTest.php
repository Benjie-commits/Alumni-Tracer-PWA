<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/** Alumni "forgot my password" by email. */
class PasswordResetTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function tokenSentTo(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    public function test_an_alumnus_can_reset_their_password_by_email(): void
    {
        [$user] = $this->alumnus();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->tokenSentTo($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a-brand-new-passphrase'])->assertOk();
    }

    public function test_the_link_opens_the_alumni_app(): void
    {
        [$user] = $this->alumnus();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, config('sunates.pwa_url').'/reset-password?token=');
        });
    }

    public function test_unknown_emails_and_staff_get_the_same_answer_and_no_email(): void
    {
        [$alumnus] = $this->alumnus();
        $staff = User::factory()->registrar()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $alumnus->email])->assertOk()->json('message');
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $forStaff = $this->postJson('/api/v1/auth/forgot-password', ['email' => $staff->email])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        $this->assertSame($known, $forStaff);
        Notification::assertNotSentTo($staff, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_resetting_signs_out_every_device(): void
    {
        [$user] = $this->alumnus();
        $user->createToken('old-phone');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->tokenSentTo($user),
            'email' => $user->email,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_wrong_or_reused_token_is_refused(): void
    {
        [$user] = $this->alumnus();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $token = $this->tokenSentTo($user);
        $payload = ['email' => $user->email, 'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase'];

        $this->postJson('/api/v1/auth/reset-password', $payload + ['token' => 'not-the-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/auth/reset-password', $payload + ['token' => $token])->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload + ['token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_the_new_password_must_be_strong_enough_and_confirmed(): void
    {
        [$user] = $this->alumnus();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'x', 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}

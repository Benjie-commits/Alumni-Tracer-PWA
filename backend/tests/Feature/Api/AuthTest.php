<?php

namespace Tests\Feature\Api;

use App\Models\User;

class AuthTest extends ApiTestCase
{
    public function test_an_alumnus_can_sign_in_and_use_the_token(): void
    {
        [$user] = $this->alumnus();

        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.profile.first_name', 'Amina');

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_credentials_are_rejected_without_saying_which_part_was_wrong(): void
    {
        [$user] = $this->alumnus();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_deactivated_accounts_cannot_sign_in(): void
    {
        $user = User::factory()->alumnus()->inactive()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable();
    }

    public function test_staff_are_pointed_to_the_admin_dashboard(): void
    {
        $staff = User::factory()->registrar()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.status', 'staff')
            ->assertJsonMissingPath('token');
    }

    public function test_logging_out_revokes_the_token(): void
    {
        [$user] = $this->alumnus();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_signing_in_twice_from_one_device_keeps_a_single_token(): void
    {
        [$user] = $this->alumnus();

        foreach ([1, 2] as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])->assertOk();
        }

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_is_rate_limited(): void
    {
        [$user] = $this->alumnus();

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
    }

    public function test_staff_tokens_cannot_use_the_alumni_api(): void
    {
        $staff = User::factory()->registrar()->create();

        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/me/profile')->assertForbidden();
    }

    // ---- regressions: brute force and enumeration ------------------------------------------------

    public function test_one_address_cannot_spray_a_password_across_many_accounts(): void
    {
        // Each email stays under its own limit of 5; the per-address limit of 20 stops the spray.
        foreach (range(1, 20) as $i) {
            $this->postJson('/api/v1/auth/login', ['email' => "person{$i}@example.com", 'password' => 'Password1'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'person21@example.com', 'password' => 'Password1'])->assertTooManyRequests();
    }

    public function test_an_unknown_email_and_a_wrong_password_get_the_same_answer(): void
    {
        [$user] = $this->alumnus();

        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();

        $this->assertSame($unknown->json('errors'), $wrong->json('errors'));
    }

    public function test_signed_in_api_requests_are_rate_limited(): void
    {
        [$user] = $this->alumnus();
        $this->actingAs($user, 'sanctum');

        foreach (range(1, 60) as $_) {
            $this->getJson('/api/v1/me')->assertOk();
        }

        $this->getJson('/api/v1/me')->assertTooManyRequests();
    }
}

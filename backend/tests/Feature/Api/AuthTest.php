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
            ->assertForbidden();
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
}

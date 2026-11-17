<?php

namespace Tests\Feature\Admin;

use App\Models\User;

class AccessControlTest extends AdminTestCase
{
    public function test_the_root_opens_the_app_and_console_guests_go_to_the_shared_sign_in(): void
    {
        $this->get('/')->assertOk()->assertSee('Always Soroti')->assertSee('/app/login');
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get(route('admin.login'))->assertRedirect('/app/login');
    }

    public function test_staff_sign_in_on_the_shared_page_and_are_handed_to_the_dashboard(): void
    {
        $staff = $this->registrar();

        $redirect = $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.status', 'staff')
            ->assertJsonMissingPath('token')
            ->json('data.redirect');

        $this->get($redirect)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($staff);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Dashboard');
        $this->assertNotNull($staff->fresh()->last_login_at);
    }

    public function test_a_hand_off_link_works_only_once(): void
    {
        $staff = $this->registrar();
        $redirect = $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->json('data.redirect');

        $this->get($redirect);
        $this->post(route('admin.logout'));

        $this->get($redirect)->assertRedirect('/app/login');
        $this->assertGuest();
    }

    public function test_bad_credentials_and_deactivated_staff_are_refused(): void
    {
        $staff = $this->registrar();
        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'wrong'])->assertJsonValidationErrors('email');

        $staff->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_an_alumnus_gets_an_app_session_not_the_console(): void
    {
        $alumnus = User::factory()->alumnus()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $alumnus->email, 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->assertGuest('web');
    }

    public function test_an_alumnus_session_is_forbidden_even_if_it_somehow_exists(): void
    {
        $alumnus = User::factory()->alumnus()->create();

        $this->actingAs($alumnus)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($alumnus)->get(route('admin.alumni.index'))->assertForbidden();
    }

    public function test_qa_viewers_are_read_only(): void
    {
        $qa = $this->qaViewer();

        $this->actingAs($qa)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($qa)->get(route('admin.alumni.index'))->assertOk();
        $this->actingAs($qa)->get(route('admin.verification'))->assertForbidden();
        $this->actingAs($qa)->get(route('admin.import'))->assertForbidden();
        $this->actingAs($qa)->get(route('admin.staff'))->assertForbidden();
    }

    public function test_registrars_cannot_manage_staff_accounts(): void
    {
        $registrar = $this->registrar();

        $this->actingAs($registrar)->get(route('admin.verification'))->assertOk();
        $this->actingAs($registrar)->get(route('admin.import'))->assertOk();
        $this->actingAs($registrar)->get(route('admin.staff'))->assertForbidden();
    }

    public function test_ict_admins_can_reach_everything(): void
    {
        $ict = $this->ictAdmin();

        foreach (['admin.dashboard', 'admin.alumni.index', 'admin.verification', 'admin.import', 'admin.staff'] as $route) {
            $this->actingAs($ict)->get(route($route))->assertOk();
        }
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->actingAs($this->registrar())->post(route('admin.logout'))->assertRedirect('/app/login');

        $this->assertGuest();
    }

    public function test_a_deactivated_account_loses_access_immediately(): void
    {
        $staff = $this->registrar();
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk();

        $staff->update(['is_active' => false]);

        $this->actingAs($staff->fresh())->get(route('admin.dashboard'))->assertForbidden();
    }
}

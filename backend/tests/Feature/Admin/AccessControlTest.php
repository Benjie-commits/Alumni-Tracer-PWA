<?php

namespace Tests\Feature\Admin;

use App\Models\User;

class AccessControlTest extends AdminTestCase
{
    public function test_the_root_sends_visitors_to_the_console_and_guests_on_to_sign_in(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/admin/alumni')->assertRedirect(route('admin.login'));
    }

    public function test_staff_can_sign_in_and_reach_the_dashboard(): void
    {
        $staff = $this->registrar();

        $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($staff);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Dashboard');
        $this->assertNotNull($staff->fresh()->last_login_at);
    }

    public function test_bad_credentials_and_deactivated_staff_are_refused(): void
    {
        $staff = $this->registrar();
        $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

        $staff->update(['is_active' => false]);
        $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_alumnus_cannot_use_the_staff_console(): void
    {
        $alumnus = User::factory()->alumnus()->create();

        $this->post(route('admin.login.store'), ['email' => $alumnus->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
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
        $this->actingAs($this->registrar())->post(route('admin.logout'))->assertRedirect(route('admin.login'));

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

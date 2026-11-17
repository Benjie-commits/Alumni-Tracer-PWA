<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ActivityLogs;
use App\Livewire\Admin\AlumniDetail;
use App\Livewire\Admin\StaffUsers;
use App\Livewire\Admin\VerificationQueue;
use App\Models\ActivityLog;
use App\Models\AlumniProfile;
use App\Models\User;
use Livewire\Livewire;

/** The staff audit trail: sensitive actions are recorded, by field name only, and only ICT can read it. */
class AuditTrailTest extends AdminTestCase
{
    public function test_editing_a_record_logs_who_and_which_fields_but_never_the_values(): void
    {
        $registrar = $this->registrar();
        $profile = $this->profile(['first_name' => 'Amina', 'last_name' => 'Okelo', 'phone' => null]);

        Livewire::actingAs($registrar)->test(AlumniDetail::class, ['profile' => $profile])
            ->set('form.last_name', 'Okello')
            ->set('form.phone', '+256700111222')
            ->call('save')
            ->assertHasNoErrors();

        $log = ActivityLog::sole();
        $this->assertSame('alumni.updated', $log->action);
        $this->assertSame($registrar->id, $log->user_id);
        $this->assertSame('AlumniProfile', $log->subject_type);
        $this->assertSame($profile->id, $log->subject_id);
        $this->assertEqualsCanonicalizing(['last_name', 'phone'], $log->changed_fields);

        $stored = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('Okello', $stored);
        $this->assertStringNotContainsString('700111222', $stored);
    }

    public function test_saving_without_changes_logs_nothing(): void
    {
        $profile = $this->profile();

        Livewire::actingAs($this->registrar())->test(AlumniDetail::class, ['profile' => $profile])->call('save');

        $this->assertSame(0, ActivityLog::count());
    }

    public function test_verification_decisions_are_logged(): void
    {
        $claim = AlumniProfile::factory()->pending()->create();

        Livewire::actingAs($this->registrar())->test(VerificationQueue::class)->call('approve', $claim->id);

        $this->assertSame(['verification.approved'], ActivityLog::pluck('action')->all());
        $this->assertSame($claim->id, ActivityLog::sole()->subject_id);
    }

    public function test_an_export_is_logged_with_its_filters_but_not_the_search_text(): void
    {
        $this->profile(['first_name' => 'Amina', 'last_name' => 'Okello', 'graduation_year' => 2023]);

        $this->actingAs($this->registrar())->get(route('admin.alumni.export', ['q' => 'Okello', 'year' => 2023]))->assertOk();

        $log = ActivityLog::sole();
        $this->assertSame('export.alumni', $log->action);
        $this->assertSame(2023, $log->meta['graduation_year']);
        $this->assertTrue($log->meta['search_used']);
        $this->assertTrue($log->meta['contact_details']);
        $this->assertStringNotContainsString('Okello', json_encode($log->getAttributes()));
    }

    public function test_staff_account_changes_are_logged_without_the_password(): void
    {
        $ict = $this->ictAdmin();
        $other = $this->registrar();

        Livewire::actingAs($ict)->test(StaffUsers::class)
            ->call('startReset', $other->id)
            ->set('newPassword', 'a-temporary-passphrase')
            ->call('savePassword');

        $log = ActivityLog::sole();
        $this->assertSame('staff.password_reset', $log->action);
        $this->assertSame($other->id, $log->subject_id);
        $this->assertStringNotContainsString('a-temporary-passphrase', json_encode($log->getAttributes()));
    }

    public function test_only_ict_can_read_the_activity_log(): void
    {
        $this->actingAs($this->ictAdmin())->get(route('admin.activity'))->assertOk()->assertSee('Activity log');
        $this->actingAs($this->registrar())->get(route('admin.activity'))->assertForbidden();
        $this->actingAs($this->qaViewer())->get(route('admin.activity'))->assertForbidden();

        Livewire::actingAs($this->registrar())->test(ActivityLogs::class)->assertForbidden();
    }

    public function test_old_entries_are_pruned_after_the_retention_period(): void
    {
        config(['sunates.audit.retention_days' => 30]);
        $old = ActivityLog::query()->create(['action' => 'export.alumni']);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        ActivityLog::query()->create(['action' => 'export.alumni']);

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertSame(1, ActivityLog::count());
    }
}

<?php

namespace Tests\Feature\Phase4;

use App\Enums\ErpSyncStatus;
use App\Models\AlumniProfile;
use App\Models\ErpSyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

class ErpCommandTest extends Phase4TestCase
{
    public function test_the_command_syncs_and_summarises_what_it_did(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord(), $this->erpRecord(['student_number' => 'SU/2026/002'])]);

        $this->artisan('sunates:sync-erp')
            ->expectsOutputToContain('Synced: 2 read, 2 new, 0 updated, 0 already up to date, 0 not graduated yet, 0 rejected')
            ->assertSuccessful();

        $this->assertSame(2, AlumniProfile::count());
        $this->assertSame('cli', ErpSyncRun::first()->trigger);
    }

    public function test_a_dry_run_says_so_and_saves_nothing(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord()]);

        $this->artisan('sunates:sync-erp --dry-run')->expectsOutputToContain('Preview (nothing saved): 1 read, 1 new')->assertSuccessful();

        $this->assertSame(0, AlumniProfile::count());
    }

    public function test_a_full_run_can_be_asked_for(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord()]);

        $this->artisan('sunates:sync-erp --full')->assertSuccessful();

        $this->assertTrue(ErpSyncRun::first()->full);
    }

    public function test_rejected_records_are_listed_by_student_and_do_not_fail_the_run(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord(['student_number' => 'SU/2026/009', 'last_name' => ''])]);

        $this->artisan('sunates:sync-erp')
            ->expectsOutputToContain('SU/2026/009: Missing last name.')
            ->assertSuccessful();
    }

    public function test_a_failed_sync_exits_with_an_error_and_the_reason(): void
    {
        $this->useRestErp();
        Http::fake(['*' => Http::response('', 401)]);

        $this->artisan('sunates:sync-erp')->expectsOutputToContain('The sync failed: The ERP refused our credentials')->assertFailed();

        $this->assertSame(ErpSyncStatus::Failed, ErpSyncRun::first()->status);
    }

    public function test_run_by_hand_in_standalone_mode_says_the_integration_is_off(): void
    {
        config(['sunates.erp.driver' => 'none']);

        $this->artisan('sunates:sync-erp')->expectsOutputToContain('switched off')->assertFailed();
    }

    public function test_the_scheduled_run_is_silent_and_harmless_in_standalone_mode(): void
    {
        config(['sunates.erp.driver' => 'none']);

        $this->artisan('sunates:sync-erp --scheduled')->doesntExpectOutputToContain('switched off')->assertSuccessful();

        $this->assertSame(0, ErpSyncRun::count(), 'no run is recorded for something that did not happen');
    }

    public function test_the_scheduled_run_is_recorded_as_automatic(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord()]);

        $this->artisan('sunates:sync-erp --scheduled')->assertSuccessful();

        $this->assertSame('scheduled', ErpSyncRun::first()->trigger);
    }

    public function test_the_command_refuses_to_start_while_another_run_is_going(): void
    {
        $this->useRestErp();
        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'manual', 'status' => ErpSyncStatus::Running, 'started_at' => now()]);

        $this->artisan('sunates:sync-erp')->expectsOutputToContain('already')->assertFailed();
    }

    public function test_the_sync_is_on_the_daily_schedule_at_the_configured_time(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'sunates:sync-erp'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--scheduled', $events->first()->command);
        $this->assertSame('30 2 * * *', $events->first()->expression);
    }
}

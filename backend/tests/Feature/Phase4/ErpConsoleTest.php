<?php

namespace Tests\Feature\Phase4;

use App\Enums\ErpSyncStatus;
use App\Jobs\RunErpSync;
use App\Livewire\Admin\ErpSync;
use App\Models\AlumniProfile;
use App\Models\ErpSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

class ErpConsoleTest extends Phase4TestCase
{
    private function ict(): User
    {
        return User::factory()->ictAdmin()->create();
    }

    private function registrar(): User
    {
        return User::factory()->registrar()->create();
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_registrar_and_ict_can_open_the_page(): void
    {
        foreach ([$this->registrar(), $this->ict()] as $staff) {
            $this->actingAs($staff)->get(route('admin.erp'))->assertOk()->assertSee('SorotiUniERP sync');
        }
    }

    public function test_qa_viewers_alumni_and_guests_cannot(): void
    {
        $this->actingAs(User::factory()->qaViewer()->create())->get(route('admin.erp'))->assertForbidden();
        $this->actingAs(User::factory()->alumnus()->create())->get(route('admin.erp'))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.erp'))->assertRedirect(route('admin.login'));
    }

    public function test_the_navigation_offers_it_to_managers(): void
    {
        $this->actingAs($this->registrar())->get(route('admin.dashboard'))->assertSee('SorotiUniERP sync');
        $this->actingAs(User::factory()->qaViewer()->create())->get(route('admin.dashboard'))->assertDontSee('SorotiUniERP sync');
    }

    public function test_the_registrar_can_read_but_not_start_anything(): void
    {
        $this->useRestErp();
        Queue::fake();

        $registrar = $this->registrar();

        Livewire::actingAs($registrar)->test(ErpSync::class)
            ->assertSee('Only the Directorate of ICT can start a sync')
            ->assertDontSee('Sync now');

        // A forbidden answer leaves no component state behind, so each forged call gets a fresh page.
        foreach (['syncNow', 'preview', 'syncEverything', 'check'] as $action) {
            Livewire::actingAs($registrar)->test(ErpSync::class)->call($action)->assertForbidden();
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, ErpSyncRun::count());
    }

    // ---- standalone mode ------------------------------------------------------------------------

    public function test_in_standalone_mode_the_page_explains_and_offers_no_buttons(): void
    {
        config(['sunates.erp.driver' => 'none']);

        Livewire::actingAs($this->ict())->test(ErpSync::class)
            ->assertSee('Off · standalone mode')
            ->assertSee('Import from spreadsheet')
            ->assertDontSee('Sync now')
            ->assertDontSee('Runs automatically');
    }

    public function test_starting_a_sync_in_standalone_mode_is_refused_even_if_the_call_is_forged(): void
    {
        config(['sunates.erp.driver' => 'none']);
        Queue::fake();

        Livewire::actingAs($this->ict())->test(ErpSync::class)->call('syncNow')->assertHasErrors('sync');

        Queue::assertNothingPushed();
    }

    // ---- running ----------------------------------------------------------------------------------

    public function test_the_page_shows_where_the_hook_reads_from_but_never_the_credential(): void
    {
        $this->useRestErp();

        $html = Livewire::actingAs($this->ict())->test(ErpSync::class)->html();

        $this->assertStringContainsString('REST API at erp.soroti.example', $html);
        $this->assertStringContainsString('Every day at 02:30', $html);
        $this->assertStringNotContainsString('erp-secret-token', $html);
    }

    public function test_ict_can_queue_a_preview_and_a_live_sync(): void
    {
        $this->useRestErp();
        Queue::fake();

        $page = Livewire::actingAs($ict = $this->ict())->test(ErpSync::class)->call('preview')->assertSee('Preview started');

        $run = ErpSyncRun::first();
        $this->assertTrue($run->dry_run);
        $this->assertSame($ict->id, $run->started_by);
        $this->assertSame('manual', $run->trigger);
        Queue::assertPushed(RunErpSync::class, fn (RunErpSync $job) => $job->runId === $run->id);

        $run->update(['status' => ErpSyncStatus::Succeeded]);
        $page->call('syncNow')->assertSee('Sync started');
        $this->assertFalse(ErpSyncRun::latest('id')->first()->dry_run);

        ErpSyncRun::latest('id')->first()->update(['status' => ErpSyncStatus::Succeeded]);
        $page->call('syncEverything');
        $this->assertTrue(ErpSyncRun::latest('id')->first()->full);
    }

    public function test_the_queued_job_does_the_sync_and_the_page_then_shows_the_result(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord(), $this->erpRecord(['student_number' => 'SU/2026/002', 'last_name' => 'Ojok'])]);

        // The test queue runs jobs inline, which is what a worker does a moment later in real life.
        $page = Livewire::actingAs($this->ict())->test(ErpSync::class)->call('syncNow');

        $this->assertSame(2, AlumniProfile::count());
        $page->assertSee('Finished')->assertSee('Live')->assertSee('everything (no earlier sync to build on)');
    }

    public function test_the_history_says_what_each_run_actually_asked_for(): void
    {
        $this->useRestErp();
        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'manual', 'full' => true, 'status' => ErpSyncStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'scheduled', 'since' => now()->subDay()->setTimezone('UTC'), 'status' => ErpSyncStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);

        Livewire::actingAs($this->registrar())->test(ErpSync::class)
            ->assertSee('everything, as asked')
            ->assertSee('changes since 4 Oct, 10:00'); // "now" is 5 Oct 10:00 Uganda time, so a day earlier is 4 Oct 10:00
    }

    public function test_a_second_sync_cannot_be_started_while_one_is_waiting(): void
    {
        $this->useRestErp();
        Queue::fake();

        $page = Livewire::actingAs($this->ict())->test(ErpSync::class)->call('syncNow');
        $page->call('syncNow')->assertHasErrors('sync')->assertSee('already waiting or running');

        $this->assertSame(1, ErpSyncRun::count());
        Queue::assertPushed(RunErpSync::class, 1);
    }

    public function test_the_page_refreshes_itself_only_while_a_sync_is_active(): void
    {
        $this->useRestErp();
        $page = Livewire::actingAs($this->ict())->test(ErpSync::class);
        $page->assertDontSeeHtml('wire:poll');

        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'manual', 'status' => ErpSyncStatus::Running, 'started_at' => now()]);

        Livewire::actingAs($this->ict())->test(ErpSync::class)->assertSeeHtml('wire:poll.3s');
    }

    // ---- testing the connection ------------------------------------------------------------------------

    public function test_the_connection_test_shows_a_green_or_red_verdict(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord()]);

        Livewire::actingAs($this->ict())->test(ErpSync::class)->call('check')
            ->assertSee('Connected. The first record has every field we need.');
    }

    public function test_a_refused_connection_test_says_why_and_saves_nothing(): void
    {
        $this->useRestErp();
        Http::fake(['*' => Http::response('', 403)]);

        Livewire::actingAs($this->ict())->test(ErpSync::class)->call('check')
            ->assertSee('The ERP refused our credentials');

        $this->assertSame(0, ErpSyncRun::count());
        $this->assertSame(0, AlumniProfile::count());
    }

    // ---- history ---------------------------------------------------------------------------------------------

    public function test_the_history_shows_counts_failures_and_who_started_each_run(): void
    {
        $this->useRestErp();
        $ict = User::factory()->ictAdmin()->create(['name' => 'Ivan Admin']);
        ErpSyncRun::create([
            'driver' => 'rest', 'trigger' => 'manual', 'started_by' => $ict->id, 'status' => ErpSyncStatus::Succeeded,
            'fetched' => 120, 'created' => 7, 'updated' => 3, 'unchanged' => 108, 'not_graduated' => 2, 'rejected' => 0,
            'started_at' => now(), 'finished_at' => now(),
        ]);
        ErpSyncRun::create([
            'driver' => 'rest', 'trigger' => 'scheduled', 'status' => ErpSyncStatus::Failed,
            'error' => 'The ERP refused our credentials (HTTP 401). Check ERP_REST_TOKEN and ERP_REST_AUTH.',
            'started_at' => now(), 'finished_at' => now(),
        ]);

        Livewire::actingAs($this->registrar())->test(ErpSync::class)
            ->assertSee('Ivan Admin')
            ->assertSee('automatic')
            ->assertSee('Failed')
            ->assertSee('The ERP refused our credentials')
            ->assertSee('120')
            ->assertSee('108');
    }

    public function test_problems_can_be_expanded_and_name_the_student(): void
    {
        $this->useRestErp();
        $run = ErpSyncRun::create([
            'driver' => 'rest', 'trigger' => 'cli', 'status' => ErpSyncStatus::Succeeded, 'fetched' => 2, 'created' => 1, 'rejected' => 1,
            'report' => ['errors' => [['row' => 2, 'message' => 'SU/2026/009: Missing last name.']], 'warnings' => [['row' => 1, 'message' => 'SU/2026/001: Programme has no school']]],
            'started_at' => now(), 'finished_at' => now(),
        ]);

        $page = Livewire::actingAs($this->registrar())->test(ErpSync::class)
            ->assertSee('Finished with problems')
            ->assertDontSee('SU/2026/009: Missing last name.');

        $page->call('toggle', $run->id)
            ->assertSee('SU/2026/009: Missing last name.')
            ->assertSee('SU/2026/001: Programme has no school')
            ->call('toggle', $run->id)
            ->assertDontSee('SU/2026/009: Missing last name.');
    }

    public function test_a_run_with_no_report_does_not_break_the_page(): void
    {
        $this->useRestErp();
        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'cli', 'status' => ErpSyncStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'cli', 'status' => ErpSyncStatus::Queued]);

        Livewire::actingAs($this->registrar())->test(ErpSync::class)->assertOk()->assertSee('Waiting to start');
    }
}

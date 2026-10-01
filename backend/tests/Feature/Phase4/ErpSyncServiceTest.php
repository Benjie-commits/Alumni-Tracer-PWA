<?php

namespace Tests\Feature\Phase4;

use App\Enums\ErpSyncStatus;
use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\ErpSyncRun;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use App\Services\Erp\Contracts\GraduateSource;
use App\Services\Erp\ErpSourceException;
use App\Services\Erp\ErpSyncBusy;
use App\Services\Erp\ErpSyncService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ErpSyncServiceTest extends Phase4TestCase
{
    private function service(): ErpSyncService
    {
        return app(ErpSyncService::class);
    }

    private function sync(bool $dryRun = false, bool $full = false, string $trigger = 'cli'): ErpSyncRun
    {
        return $this->service()->execute($this->service()->start($trigger, $dryRun, $full));
    }

    // ---- turning ERP records into alumni --------------------------------------------------------

    public function test_graduating_students_become_unclaimed_alumni_records(): void
    {
        $this->useRestErp();
        $this->fakeErp([
            $this->erpRecord(),
            $this->erpRecord(['student_number' => 'SU/2026/002', 'first_name' => 'Peter', 'last_name' => 'Ojok', 'programme' => 'BSc Chemistry']),
            $this->erpRecord(['student_number' => 'SU/2026/003', 'first_name' => 'Grace', 'last_name' => 'Achieng']),
        ]);

        $run = $this->sync();

        $this->assertSame(ErpSyncStatus::Succeeded, $run->status);
        $this->assertSame(3, $run->fetched);
        $this->assertSame(3, $run->created);
        $this->assertSame(3, AlumniProfile::count());

        $amina = AlumniProfile::where('student_number', 'SU/2026/001')->first();
        $this->assertSame(RecordSource::Erp, $amina->record_source);
        $this->assertSame(VerificationStatus::Unclaimed, $amina->verification_status);
        $this->assertNull($amina->user_id, 'nobody is signed up on their behalf; they claim it themselves');
        $this->assertSame(2026, $amina->graduation_year);
        $this->assertSame('2026-07-15', $amina->graduation_date->toDateString());
        $this->assertSame('Second Class Upper', $amina->class_of_award);
        $this->assertSame('female', $amina->gender);
        $this->assertSame('BSc Biology', $amina->programme->name);
        $this->assertSame('School of Science', $amina->programme->department->school->name);
        $this->assertSame('amina@example.test', $amina->email);
    }

    public function test_a_new_programme_the_erp_introduces_is_created(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord(['school' => 'School of Engineering', 'department' => 'Civil', 'programme' => 'BEng Civil'])]);

        $run = $this->sync();

        $this->assertTrue(School::where('name', 'School of Engineering')->exists());
        $this->assertTrue(Programme::where('name', 'BEng Civil')->exists());
        $this->assertSame(3, $run->report['reference_created']);
    }

    public function test_the_erp_corrects_what_the_registrar_holds_but_never_overwrites_an_alumnus_own_details(): void
    {
        $this->useRestErp();
        $user = User::factory()->alumnus()->create();
        $profile = $this->graduate([
            'student_number' => 'SU/2026/001', 'last_name' => 'Okelo', 'class_of_award' => null,
            'email' => 'chosen@by-alumnus.test', 'phone' => '+256700999888',
            'user_id' => $user->id, 'verification_status' => VerificationStatus::Verified,
            'record_source' => RecordSource::RegistrarImport,
        ]);
        $this->fakeErp([$this->erpRecord(['last_name' => 'Okello', 'email' => 'old-erp@example.test', 'phone' => '+256700111222'])]);

        $run = $this->sync();

        $profile->refresh();
        $this->assertSame(1, $run->updated);
        $this->assertSame('Okello', $profile->last_name, 'Registrar-owned: the ERP is the newer word');
        $this->assertSame('Second Class Upper', $profile->class_of_award);
        $this->assertSame('chosen@by-alumnus.test', $profile->email, 'the alumnus own contact details are theirs');
        $this->assertSame('+256700999888', $profile->phone);
        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame(VerificationStatus::Verified, $profile->verification_status);
        $this->assertSame(RecordSource::RegistrarImport, $profile->record_source, 'the record keeps where it first came from');
    }

    public function test_a_blank_erp_field_never_erases_what_we_hold(): void
    {
        $this->useRestErp();
        $profile = $this->graduate(['student_number' => 'SU/2026/001', 'class_of_award' => 'First Class', 'gender' => 'female']);
        $this->fakeErp([$this->erpRecord(['class_of_award' => null, 'gender' => '', 'graduation_date' => null, 'graduation_year' => null])]);

        $this->sync();

        $profile->refresh();
        $this->assertSame('First Class', $profile->class_of_award);
        $this->assertSame('female', $profile->gender);
        $this->assertSame(2024, $profile->graduation_year);
    }

    public function test_running_the_same_feed_twice_changes_nothing_the_second_time(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord(), $this->erpRecord(['student_number' => 'SU/2026/002', 'last_name' => 'Ojok'])]);

        $this->sync();
        $second = $this->sync();

        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(2, $second->unchanged);
        $this->assertSame(2, AlumniProfile::count());
    }

    public function test_students_the_erp_says_have_not_graduated_are_not_made_alumni(): void
    {
        $this->useRestErp(['fields' => array_replace(config('sunates.erp.fields'), ['status' => 'status'])]);
        $this->fakeErp([
            $this->erpRecord(['status' => 'Graduated']),
            $this->erpRecord(['student_number' => 'SU/2027/050', 'status' => 'Enrolled']),
            $this->erpRecord(['student_number' => 'SU/2027/051', 'status' => 'Suspended']),
        ]);

        $run = $this->sync();

        $this->assertSame(3, $run->fetched);
        $this->assertSame(1, $run->created);
        $this->assertSame(2, $run->not_graduated);
        $this->assertFalse(AlumniProfile::where('student_number', 'SU/2027/050')->exists());
    }

    public function test_a_record_that_cannot_be_used_is_turned_away_by_name_and_the_rest_still_go_in(): void
    {
        $this->useRestErp();
        $this->fakeErp([
            $this->erpRecord(),
            $this->erpRecord(['student_number' => 'SU/2026/009', 'last_name' => '']),
            $this->erpRecord(['student_number' => 'SU/2026/010', 'graduation_year' => '20x6']),
        ]);

        $run = $this->sync();

        $this->assertSame(ErpSyncStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->created);
        $this->assertSame(2, $run->rejected);
        $messages = array_column($run->report['errors'], 'message');
        $this->assertContains('SU/2026/009: Missing last name.', $messages, 'says which student, not just "row 2"');
        $this->assertContains("SU/2026/010: Invalid graduation year '20x6'.", $messages);
    }

    public function test_a_deleted_record_is_not_silently_brought_back(): void
    {
        $this->useRestErp();
        $gone = $this->graduate(['student_number' => 'SU/2026/001']);
        $gone->delete();
        $this->fakeErp([$this->erpRecord()]);

        $run = $this->sync();

        $this->assertSame(1, $run->rejected);
        $this->assertStringContainsString('belongs to a deleted record', $run->report['errors'][0]['message']);
        $this->assertTrue($gone->fresh()->trashed(), 'still deleted');
        $this->assertSame(1, AlumniProfile::withTrashed()->count(), 'and no duplicate was created');
    }

    // ---- preview ------------------------------------------------------------------------------

    public function test_a_preview_reports_the_changes_and_saves_nothing(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord(), $this->erpRecord(['student_number' => 'SU/2026/002'])]);

        $run = $this->sync(dryRun: true);

        $this->assertTrue($run->dry_run);
        $this->assertSame(ErpSyncStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->created, 'the report shows what a real run would do');
        $this->assertSame(0, AlumniProfile::count(), 'and nothing was saved');
        $this->assertNull($run->cursor, 'a preview must not move the point the next sync starts from');
        $this->assertNull(ErpSyncRun::lastCursor());
    }

    // ---- incremental reading ------------------------------------------------------------------

    public function test_the_next_sync_starts_from_the_newest_change_it_saw_less_an_overlap(): void
    {
        $this->useRestErp(['fields' => array_replace(config('sunates.erp.fields'), ['updated_at' => 'modified'])]);
        $this->fakeErp([
            $this->erpRecord(['modified' => '2026-10-01T08:00:00+03:00']),
            $this->erpRecord(['student_number' => 'SU/2026/002', 'modified' => '2026-10-03T16:30:00+03:00']),
        ]);

        $first = $this->sync();

        $this->assertSame('2026-10-03 16:30', $first->cursor->timezone('Africa/Kampala')->format('Y-m-d H:i'));
        $this->assertNull($first->since, 'the first sync reads everything');
        $this->assertArrayNotHasKey('updated_since', $this->erpRequests[0]['query']);

        $this->fakeErp([]);
        $second = $this->sync();

        $this->assertSame('2026-10-02T16:30:00+03:00', $this->erpRequests[0]['query']['updated_since'], '24 hours before the newest change');
        $this->assertSame('2026-10-02 16:30', $second->since->timezone('Africa/Kampala')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-03 16:30', ErpSyncRun::lastCursor()->timezone('Africa/Kampala')->format('Y-m-d H:i'), 'an empty sync keeps the old point');
    }

    public function test_a_full_run_ignores_the_saved_point(): void
    {
        $this->useRestErp(['fields' => array_replace(config('sunates.erp.fields'), ['updated_at' => 'modified'])]);
        $this->fakeErp([$this->erpRecord(['modified' => '2026-10-03T16:30:00+03:00'])]);
        $this->sync();

        $this->fakeErp([$this->erpRecord(['modified' => '2026-10-03T16:30:00+03:00'])]);
        $full = $this->sync(full: true);

        $this->assertTrue($full->full);
        $this->assertArrayNotHasKey('updated_since', $this->erpRequests[0]['query']);
    }

    public function test_the_point_does_not_move_past_a_record_that_was_turned_away(): void
    {
        $this->useRestErp(['fields' => array_replace(config('sunates.erp.fields'), ['updated_at' => 'modified'])]);
        $this->fakeErp([
            $this->erpRecord(['modified' => '2026-10-01T08:00:00+03:00']),
            $this->erpRecord(['student_number' => 'SU/2026/009', 'last_name' => '', 'modified' => '2026-10-03T08:00:00+03:00']),
        ]);

        $run = $this->sync();

        $this->assertSame(1, $run->rejected);
        $this->assertNull($run->cursor, 'the rejected student must be offered again next time, not skipped past for good');
        $this->assertNull(ErpSyncRun::lastCursor());
    }

    public function test_a_change_date_in_the_future_cannot_push_the_next_sync_past_real_changes(): void
    {
        $this->useRestErp(['fields' => array_replace(config('sunates.erp.fields'), ['updated_at' => 'modified'])]);
        $this->fakeErp([$this->erpRecord(['modified' => '2031-01-01T00:00:00+03:00'])]);

        $run = $this->sync();

        $this->assertTrue($run->cursor->lessThanOrEqualTo(now()));
    }

    // ---- failures -----------------------------------------------------------------------------

    public function test_if_the_erp_fails_half_way_nothing_is_saved_and_the_run_says_why(): void
    {
        $this->useRestErp();
        $page = 0;
        Http::fake(function () use (&$page) {
            return ++$page === 1
                ? Http::response(['data' => [$this->erpRecord(), $this->erpRecord(['student_number' => 'SU/2026/002'])]])
                : Http::response('boom', 503);
        });

        $run = $this->sync();

        $this->assertSame(ErpSyncStatus::Failed, $run->status);
        $this->assertStringContainsString('HTTP 503', $run->error);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, AlumniProfile::count(), 'a half-read feed is never half-saved');
        $this->assertNull(ErpSyncRun::lastCursor());
    }

    public function test_a_failure_never_blocks_the_next_attempt(): void
    {
        $this->useRestErp();
        Http::fake(['*' => Http::response('', 500)]);
        $this->assertSame(ErpSyncStatus::Failed, $this->sync()->status);

        $this->assertInstanceOf(ErpSyncRun::class, $this->service()->start('cli'));
    }

    public function test_an_unexpected_crash_is_recorded_without_leaking_its_details(): void
    {
        $this->useRestErp();
        $this->app->bind(GraduateSource::class, fn () => new class implements GraduateSource
        {
            public function fetch(?CarbonInterface $since, ?int $limit = null): iterable
            {
                throw new RuntimeException('SQLSTATE secret internals password=hunter2');
            }
        });

        $run = $this->sync();

        $this->assertSame(ErpSyncStatus::Failed, $run->status);
        $this->assertStringNotContainsString('hunter2', $run->error);
        $this->assertStringContainsString('application log', $run->error);
    }

    public function test_a_feed_far_bigger_than_any_graduating_class_is_refused(): void
    {
        $this->useRestErp(['max_records' => 3]);
        $this->fakeErp(array_map(fn ($i) => $this->erpRecord(['student_number' => "SU/2026/{$i}"]), range(1, 5)));

        $run = $this->sync();

        $this->assertSame(ErpSyncStatus::Failed, $run->status);
        $this->assertStringContainsString('more than 3 records', $run->error);
        $this->assertSame(0, AlumniProfile::count());
    }

    // ---- starting a run ------------------------------------------------------------------------

    public function test_standalone_mode_cannot_be_started(): void
    {
        config(['sunates.erp.driver' => 'none']);

        $this->assertFalse($this->service()->isEnabled());
        $this->expectException(ErpSourceException::class);
        $this->service()->start('cli');
    }

    public function test_only_one_run_at_a_time(): void
    {
        $this->useRestErp();
        $this->service()->start('cli');

        $this->expectException(ErpSyncBusy::class);
        $this->service()->start('manual');
    }

    public function test_a_run_the_worker_never_finished_stops_blocking_after_half_an_hour(): void
    {
        $this->useRestErp();
        $stuck = $this->service()->start('cli');
        $stuck->forceFill(['status' => ErpSyncStatus::Running, 'created_at' => now()->subMinutes(45)])->save();

        $next = $this->service()->start('cli');

        $this->assertNotSame($stuck->id, $next->id);
        $stuck->refresh();
        $this->assertSame(ErpSyncStatus::Failed, $stuck->status);
        $this->assertStringContainsString('never finished', $stuck->error);
    }

    public function test_executing_a_run_twice_does_the_work_once(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord()]);
        $run = $this->service()->start('cli');

        $this->service()->execute($run);
        $requests = count($this->erpRequests);
        $again = $this->service()->execute($run->fresh());

        $this->assertSame(ErpSyncStatus::Succeeded, $again->status);
        $this->assertCount($requests, $this->erpRequests, 'a duplicate queue job does not read the ERP again');
    }

    public function test_who_started_a_run_and_how_is_recorded(): void
    {
        $this->useRestErp();
        $ict = User::factory()->ictAdmin()->create();

        $run = $this->service()->start('manual', by: $ict);

        $this->assertSame($ict->id, $run->started_by);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame('rest', $run->driver);
        $this->assertSame(ErpSyncStatus::Queued, $run->status);
    }

    // ---- testing the connection --------------------------------------------------------------------

    public function test_the_connection_test_passes_when_the_first_record_has_what_we_need(): void
    {
        $this->useRestErp();
        $this->fakeErp([$this->erpRecord()]);

        $result = $this->service()->check();

        $this->assertTrue($result->ok);
        $this->assertCount(1, $this->erpRequests, 'asks for one record only');
        $this->assertSame(0, AlumniProfile::count());
    }

    public function test_the_connection_test_says_when_the_field_map_does_not_match(): void
    {
        $this->useRestErp();
        $this->fakeErp([['regNo' => 'SU/1', 'given' => 'A', 'family' => 'B']]);

        $result = $this->service()->check();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('student number, first name, last name', $result->message);
        $this->assertStringContainsString('ERP_FIELD_MAP', $result->message);
    }

    public function test_the_connection_test_notices_an_empty_feed(): void
    {
        $this->useRestErp();
        $this->fakeErp([]);

        $result = $this->service()->check();

        $this->assertTrue($result->ok, 'reachable is reachable');
        $this->assertStringContainsString('returned no graduates', $result->message);
    }

    public function test_the_connection_test_reports_a_refusal_without_the_credential(): void
    {
        $this->useRestErp();
        Http::fake(['*' => Http::response('', 401)]);

        $result = $this->service()->check();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('refused our credentials', $result->message);
        $this->assertStringNotContainsString('erp-secret-token', $result->message);
    }

    public function test_the_connection_test_in_standalone_mode_says_it_is_off(): void
    {
        config(['sunates.erp.driver' => 'none']);

        $result = $this->service()->check();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('switched off', $result->message);
    }

    public function test_the_sync_does_not_touch_the_survey_or_nudge_clocks_of_existing_alumni(): void
    {
        $this->useRestErp();
        $stamp = Carbon::parse('2026-03-01 06:00', 'UTC');
        $profile = $this->graduate(['student_number' => 'SU/2026/001', 'profile_updated_at' => $stamp, 'last_survey_completed_at' => $stamp]);
        $this->fakeErp([$this->erpRecord(['last_name' => 'Okello-Corrected'])]);

        $this->sync();

        $profile->refresh();
        $this->assertSame('Okello-Corrected', $profile->last_name);
        $this->assertTrue($profile->profile_updated_at->equalTo($stamp), 'only the alumnus confirms their record; an ERP change is not a confirmation');
        $this->assertTrue($profile->last_survey_completed_at->equalTo($stamp));
    }
}

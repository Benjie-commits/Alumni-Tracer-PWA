<?php

namespace Tests\Feature\Phase4;

use App\Enums\ErpSyncStatus;
use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use App\Livewire\Admin\ErpSync;
use App\Models\AlumniProfile;
use App\Models\ErpSyncRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

class ErpInboundTest extends Phase4TestCase
{
    private const TOKEN = 'inbound-secret-key-for-the-erp-0123456789';

    private function switchOn(): void
    {
        config(['sunates.erp.inbound.token' => self::TOKEN]);
    }

    /** @return array<string, string> */
    private function auth(?string $token = self::TOKEN): array
    {
        return $token === null ? [] : ['Authorization' => "Bearer {$token}"];
    }

    private function push(array $graduates, array $extra = [], ?string $token = self::TOKEN)
    {
        return $this->postJson('/api/v1/erp/graduates', ['graduates' => $graduates] + $extra, $this->auth($token));
    }

    // ---- switched off until a key exists ----------------------------------------------------------

    public function test_until_a_key_is_set_the_endpoint_does_not_exist(): void
    {
        config(['sunates.erp.inbound.token' => '']);

        $this->getJson('/api/v1/erp/status', $this->auth('anything'))->assertNotFound();
        $this->postJson('/api/v1/erp/graduates', ['graduates' => [$this->erpRecord()]], $this->auth('anything'))->assertNotFound();
        $this->assertSame(0, AlumniProfile::count());
    }

    public function test_it_works_whether_or_not_the_pull_side_is_on(): void
    {
        $this->switchOn();
        config(['sunates.erp.driver' => 'none']);

        $this->push([$this->erpRecord()])->assertOk()->assertJsonPath('data.created', 1);
    }

    // ---- who may use it ---------------------------------------------------------------------------------

    public function test_the_wrong_or_missing_key_is_refused_and_nothing_is_saved(): void
    {
        $this->switchOn();

        foreach ([null, 'wrong-key', self::TOKEN.'x', substr(self::TOKEN, 0, -1), ''] as $token) {
            $this->push([$this->erpRecord()], token: $token)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        }

        $this->getJson('/api/v1/erp/status')->assertUnauthorized();
        $this->assertSame(0, AlumniProfile::count());
        $this->assertSame(0, ErpSyncRun::count());
    }

    public function test_an_alumnus_or_staff_login_is_not_a_key(): void
    {
        $this->switchOn();
        $token = User::factory()->alumnus()->create()->createToken('app')->plainTextToken;

        $this->push([$this->erpRecord()], token: $token)->assertUnauthorized();
        $this->assertSame(0, AlumniProfile::count());
    }

    public function test_the_right_key_is_accepted_in_the_header_only_not_the_query_string(): void
    {
        $this->switchOn();

        $this->postJson('/api/v1/erp/graduates?token='.self::TOKEN, ['graduates' => [$this->erpRecord()]])->assertUnauthorized();
        $this->getJson('/api/v1/erp/status?api_token='.self::TOKEN)->assertUnauthorized();
    }

    public function test_status_tells_the_erp_team_what_to_send(): void
    {
        $this->switchOn();
        config(['sunates.erp.fields.student_number' => 'regNo', 'sunates.erp.fields.last_name' => 'familyName', 'sunates.erp.fields.status' => 'state']);

        $response = $this->getJson('/api/v1/erp/status', $this->auth())->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.max_batch', 500)
            ->assertJsonPath('data.required_fields', ['regNo', 'first_name', 'familyName'])
            ->assertJsonPath('data.status_field', 'state');

        $accepted = $response->json('data.accepted_fields');
        $this->assertContains('regNo', $accepted, "the ERP's own name, not ours");
        $this->assertContains('familyName', $accepted);
        $this->assertNotContains('student_number', $accepted);
        $this->assertNotContains('state', $accepted, 'the status field is reported separately');
    }

    // ---- what a push does ------------------------------------------------------------------------------------

    public function test_pushed_graduates_become_alumni_records_by_the_same_rules_as_a_pull(): void
    {
        $this->switchOn();

        $response = $this->push([
            $this->erpRecord(),
            $this->erpRecord(['student_number' => '2601600002', 'first_name' => 'Peter', 'last_name' => 'Ojok', 'programme' => 'BSc Chemistry']),
        ]);

        $response->assertOk()->assertJson(['data' => ['received' => 2, 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'not_graduated' => 0, 'rejected' => 0, 'problems' => [], 'dry_run' => false]]);

        $amina = AlumniProfile::where('student_number', '2601600001')->first();
        $this->assertSame(RecordSource::Erp, $amina->record_source);
        $this->assertSame(VerificationStatus::Unclaimed, $amina->verification_status);
        $this->assertNull($amina->user_id);
        $this->assertSame('BSc Biology', $amina->programme->name);
    }

    public function test_the_erp_can_use_its_own_field_names(): void
    {
        $this->switchOn();
        config(['sunates.erp.fields' => array_replace(config('sunates.erp.fields'), [
            'student_number' => 'regNo', 'first_name' => 'givenName', 'last_name' => 'familyName', 'programme' => 'course.name',
            'school' => 'course.school', 'department' => 'course.department', 'graduation_date' => 'conferredOn',
            'other_names' => null, 'gender' => null, 'date_of_birth' => null, 'graduation_year' => null, 'class_of_award' => null, 'email' => null, 'phone' => null,
        ])]);

        $this->push([[
            'regNo' => '2601600050', 'givenName' => 'Grace', 'familyName' => 'Mugisha',
            'course' => ['name' => 'BSc Midwifery', 'school' => 'School of Health Sciences', 'department' => 'Nursing'],
            'conferredOn' => '2026-07-15T00:00:00Z',
        ]])->assertOk()->assertJsonPath('data.created', 1);

        $grace = AlumniProfile::where('student_number', '2601600050')->first();
        $this->assertSame('Grace', $grace->first_name);
        $this->assertSame('BSc Midwifery', $grace->programme->name);
        $this->assertSame('2026-07-15', $grace->graduation_date->toDateString());
        $this->assertSame(2026, $grace->graduation_year);
    }

    public function test_resending_the_same_graduates_changes_nothing(): void
    {
        $this->switchOn();
        $batch = [$this->erpRecord(), $this->erpRecord(['student_number' => '2601600002', 'last_name' => 'Ojok'])];

        $this->push($batch)->assertOk();
        $this->push($batch)->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.unchanged', 2);

        $this->assertSame(2, AlumniProfile::count());
    }

    public function test_an_alumnus_own_contact_details_are_never_overwritten(): void
    {
        $this->switchOn();
        $profile = $this->graduate(['student_number' => '2601600001', 'email' => 'chosen@alumnus.test', 'phone' => '+256700999888', 'last_name' => 'Okelo']);

        $this->push([$this->erpRecord(['last_name' => 'Okello', 'email' => 'erp@example.test', 'phone' => '+256700111222'])])->assertOk()->assertJsonPath('data.updated', 1);

        $profile->refresh();
        $this->assertSame('Okello', $profile->last_name);
        $this->assertSame('chosen@alumnus.test', $profile->email);
        $this->assertSame('+256700999888', $profile->phone);
    }

    public function test_students_who_have_not_graduated_are_counted_but_not_made_alumni(): void
    {
        $this->switchOn();
        config(['sunates.erp.fields.status' => 'status']);

        $this->push([
            $this->erpRecord(['status' => 'Graduated']),
            $this->erpRecord(['student_number' => '2701600050', 'status' => 'Enrolled']),
        ])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.not_graduated', 1);

        $this->assertFalse(AlumniProfile::where('student_number', '2701600050')->exists());
    }

    public function test_unusable_records_are_reported_by_student_and_the_rest_are_still_saved(): void
    {
        $this->switchOn();

        $this->push([
            $this->erpRecord(),
            $this->erpRecord(['student_number' => '2601600009', 'last_name' => '']),
            $this->erpRecord(['student_number' => '2601600010', 'graduation_year' => '20x6']),
        ])->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.rejected', 2)
            ->assertJsonPath('data.problems', ['2601600009: Missing last name.', "2601600010: Invalid graduation year '20x6'."]);

        $this->assertSame(1, AlumniProfile::count());
    }

    public function test_a_dry_run_reports_what_would_happen_and_saves_nothing(): void
    {
        $this->switchOn();

        $this->push([$this->erpRecord()], ['dry_run' => true])->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.created', 1);

        $this->assertSame(0, AlumniProfile::count());
    }

    // ---- the record of it -----------------------------------------------------------------------------------------

    public function test_each_push_is_recorded_without_the_key(): void
    {
        $this->switchOn();

        $runId = $this->push([$this->erpRecord()])->json('data.run_id');

        $run = ErpSyncRun::findOrFail($runId);
        $this->assertSame('inbound', $run->driver);
        $this->assertSame('api', $run->trigger);
        $this->assertNull($run->started_by);
        $this->assertSame(ErpSyncStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->created);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($run->toArray()));
    }

    public function test_a_push_never_moves_the_point_a_pull_continues_from(): void
    {
        $this->switchOn();
        $pullPoint = Carbon::parse('2026-10-01 09:00', 'UTC');
        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'scheduled', 'status' => ErpSyncStatus::Succeeded, 'cursor' => $pullPoint, 'started_at' => now(), 'finished_at' => now()]);
        config(['sunates.erp.fields.updated_at' => 'modified']);

        $this->push([$this->erpRecord(['modified' => '2026-10-04T10:00:00+03:00'])])->assertOk();

        $this->assertTrue(ErpSyncRun::lastCursor()->equalTo($pullPoint));
    }

    // ---- validation and limits -----------------------------------------------------------------------------------------

    public function test_the_request_must_carry_a_non_empty_list_of_graduates(): void
    {
        $this->switchOn();

        $this->postJson('/api/v1/erp/graduates', [], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('graduates');
        $this->postJson('/api/v1/erp/graduates', ['graduates' => []], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('graduates');
        $this->postJson('/api/v1/erp/graduates', ['graduates' => '2601600001'], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('graduates');
        $this->postJson('/api/v1/erp/graduates', ['graduates' => ['not a record']], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('graduates.0');
        $this->postJson('/api/v1/erp/graduates', ['graduates' => [$this->erpRecord()], 'dry_run' => 'maybe'], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('dry_run');

        $this->assertSame(0, AlumniProfile::count());
    }

    public function test_a_batch_over_the_limit_is_refused_with_advice(): void
    {
        $this->switchOn();
        config(['sunates.erp.inbound.max_batch' => 3]);

        $batch = array_map(fn ($i) => $this->erpRecord(['student_number' => "SU/2026/{$i}"]), range(1, 4));

        $this->push($batch)->assertUnprocessable()->assertJsonPath('errors.graduates.0', 'Send at most 3 graduates in one request; split larger lists into batches.');
        $this->assertSame(0, AlumniProfile::count());
    }

    public function test_while_a_pull_is_running_the_erp_is_told_to_retry(): void
    {
        $this->switchOn();
        ErpSyncRun::create(['driver' => 'rest', 'trigger' => 'manual', 'status' => ErpSyncStatus::Running, 'started_at' => now()]);

        $this->push([$this->erpRecord()])->assertStatus(409)->assertHeader('Retry-After', '60');
        $this->assertSame(0, AlumniProfile::count());
    }

    // ---- the key and the console ---------------------------------------------------------------------------------------------

    public function test_the_token_command_makes_a_long_random_key_and_writes_nothing(): void
    {
        $before = file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null;

        $this->artisan('sunates:erp-token')->expectsOutputToContain('ERP_INBOUND_TOKEN=')->assertSuccessful();

        $this->assertSame($before, file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null, '.env is untouched');
    }

    public function test_the_console_shows_whether_the_endpoint_is_on_and_labels_pushed_runs(): void
    {
        $ict = User::factory()->ictAdmin()->create();

        Livewire::actingAs($ict)->test(ErpSync::class)->assertSee('Endpoint for the ERP to push to')->assertSee('no key has been made for the ERP yet');

        $this->switchOn();
        $page = Livewire::actingAs($ict)->test(ErpSync::class)->assertSee(url('/api/v1/erp/graduates'))->assertSee('Not used yet');
        $this->assertStringNotContainsString(self::TOKEN, $page->html(), 'the key is never shown');

        $this->push([$this->erpRecord()])->assertOk();

        Livewire::actingAs($ict)->test(ErpSync::class)
            ->assertSee('Last used')
            ->assertSee('pushed by the ERP')
            ->assertSee('what the ERP chose to send');
    }
}

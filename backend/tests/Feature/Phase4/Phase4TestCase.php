<?php

namespace Tests\Feature\Phase4;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class Phase4TestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{query: array<string, string>, auth: string, key: string}> every request the fake ERP received */
    protected array $erpRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Kampala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Point the hook at a (faked) REST ERP, with pages of two so paging is always exercised. */
    protected function useRestErp(array $overrides = []): void
    {
        config([
            'sunates.erp.driver' => 'rest',
            'sunates.erp.rest.base_url' => 'https://erp.soroti.example',
            'sunates.erp.rest.path' => '/api/graduates',
            'sunates.erp.rest.auth' => 'bearer',
            'sunates.erp.rest.token' => 'erp-secret-token',
            'sunates.erp.rest.page_size' => 2,
            'sunates.erp.rest.data_key' => 'data',
        ]);

        foreach ($overrides as $key => $value) {
            config(["sunates.erp.{$key}" => $value]);
        }
    }

    /** @var list<array<string, mixed>> what the fake ERP currently holds */
    private array $erpFeed = [];

    private bool $erpFaked = false;

    /**
     * A fake ERP that serves $records a page at a time, like a real one, and notes what it was asked.
     * Calling it again swaps the records (Http::fake stubs stack and the first match wins, so the
     * stub is registered once and reads whatever the feed is now).
     *
     * @param  list<array<string, mixed>>  $records
     */
    protected function fakeErp(array $records): void
    {
        $this->erpFeed = $records;
        $this->erpRequests = [];

        if ($this->erpFaked) {
            return;
        }
        $this->erpFaked = true;

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->erpRequests[] = [
                'query' => $query,
                'auth' => $request->header('Authorization')[0] ?? '',
                'key' => $request->header('X-Api-Key')[0] ?? '',
            ];

            $size = (int) ($query['per_page'] ?? 200);
            $page = (int) ($query['page'] ?? 1);

            return Http::response(['data' => array_slice($this->erpFeed, ($page - 1) * $size, $size)]);
        });
    }

    /** @return array<string, mixed> a graduate as the ERP sends them (our own field names: the default map) */
    protected function erpRecord(array $overrides = []): array
    {
        return $overrides + [
            'student_number' => 'SU/2026/001',
            'first_name' => 'Amina',
            'last_name' => 'Okello',
            'other_names' => null,
            'gender' => 'F',
            'date_of_birth' => '2002-03-04',
            'school' => 'School of Science',
            'department' => 'Department of Biology',
            'programme' => 'BSc Biology',
            'graduation_year' => 2026,
            'graduation_date' => '2026-07-15',
            'class_of_award' => 'Second Class Upper',
            'email' => 'amina@example.test',
            'phone' => '+256700111222',
        ];
    }

    protected function programme(string $name = 'BSc Biology'): Programme
    {
        $school = School::query()->firstOrCreate(['name' => 'School of Science']);
        $department = Department::query()->firstOrCreate(['school_id' => $school->id, 'name' => 'Department of Biology']);

        return Programme::query()->firstOrCreate(['department_id' => $department->id, 'name' => $name]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function graduate(array $attributes = []): AlumniProfile
    {
        return AlumniProfile::factory()->create($attributes + [
            'first_name' => 'Amina',
            'last_name' => 'Okello',
            'graduation_year' => 2024,
            'graduation_date' => '2024-07-15',
            'programme_id' => $this->programme()->id,
            'verification_status' => VerificationStatus::Unclaimed,
        ]);
    }
}

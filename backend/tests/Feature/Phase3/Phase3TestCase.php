<?php

namespace Tests\Feature\Phase3;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SurveyCycleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

abstract class Phase3TestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SurveyCycleSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Kampala'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function programme(string $name = 'BSc Biology', ?string $school = null): Programme
    {
        $school = School::query()->firstOrCreate(['name' => $school ?? 'School of Science']);
        $department = Department::query()->firstOrCreate(['school_id' => $school->id, 'name' => 'Department of Biology']);

        return Programme::query()->firstOrCreate(['department_id' => $department->id, 'name' => $name]);
    }

    /**
     * A graduate the Registrar's records confirm (imported, with a student number).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function graduate(array $attributes = []): AlumniProfile
    {
        return AlumniProfile::factory()->create($attributes + [
            'first_name' => 'Amina',
            'last_name' => 'Okello',
            'other_names' => null,
            'graduation_year' => 2024,
            'graduation_date' => '2024-07-15',
            'programme_id' => $this->programme()->id,
            'verification_status' => VerificationStatus::Unclaimed,
        ]);
    }

    protected function alumnusAccount(?AlumniProfile $profile = null): User
    {
        $user = User::factory()->alumnus()->create();
        ($profile ?? $this->graduate())->update(['user_id' => $user->id, 'verification_status' => VerificationStatus::Verified]);

        return $user;
    }

    /** @return array<string, mixed> */
    protected function lookup(array $overrides = []): array
    {
        return $overrides + ['organisation' => 'Acme Recruitment Ltd', 'name' => 'Amina Okello'];
    }
}

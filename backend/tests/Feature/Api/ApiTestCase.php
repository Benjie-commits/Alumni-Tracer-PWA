<?php

namespace Tests\Feature\Api;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Programme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * A signed-in alumnus with a verified profile.
     *
     * @return array{0: User, 1: AlumniProfile}
     */
    protected function alumnus(array $profile = []): array
    {
        $user = User::factory()->alumnus()->create();
        $record = AlumniProfile::factory()->claimedBy($user)->create($profile + ['first_name' => 'Amina', 'last_name' => 'Okello']);

        return [$user, $record];
    }

    /** An unclaimed Registrar-imported record. */
    protected function importedRecord(array $overrides = []): AlumniProfile
    {
        return AlumniProfile::factory()->create($overrides + [
            'student_number' => 'SU/2021/014',
            'first_name' => 'Amina',
            'last_name' => 'Okello',
            'graduation_year' => 2024,
            'programme_id' => Programme::factory(),
            'verification_status' => VerificationStatus::Unclaimed,
        ]);
    }

    /** @return array<string, mixed> */
    protected function registrationPayload(array $overrides = []): array
    {
        return $overrides + [
            'student_number' => 'SU/2021/014',
            'first_name' => 'Amina',
            'last_name' => 'Okello',
            'graduation_year' => 2024,
            'email' => 'amina@example.com',
            'phone' => '+256700123456',
            'password' => 'a-good-passphrase',
            'consent' => true,
        ];
    }
}

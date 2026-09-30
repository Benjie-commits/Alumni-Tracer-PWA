<?php

namespace Database\Seeders;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fake data so the console and PWA have something to show in local development.
 * Every name is invented and every school is prefixed "DEMO", so it cannot be mistaken for real records.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoDataSeeder only runs in local or testing environments.');
        }

        $this->call(RoleSeeder::class);

        $programmes = collect([
            'DEMO School of Science' => ['DEMO Biology' => ['BSc Demo Biology', 'BSc Demo Chemistry'], 'DEMO Computing' => ['BSc Demo Computer Science']],
            'DEMO School of Education' => ['DEMO Curriculum' => ['BEd Demo Primary', 'BEd Demo Secondary']],
            'DEMO School of Business' => ['DEMO Management' => ['BBA Demo Management', 'BSc Demo Accounting']],
        ])->flatMap(function (array $departments, string $schoolName) {
            $school = School::query()->firstOrCreate(['name' => $schoolName]);

            return collect($departments)->flatMap(function (array $names, string $departmentName) use ($school) {
                $department = Department::query()->firstOrCreate(['school_id' => $school->id, 'name' => $departmentName]);

                return collect($names)->map(fn (string $name) => Programme::query()->firstOrCreate(['department_id' => $department->id, 'name' => $name]));
            });
        })->values();

        $statuses = EmploymentStatus::cases();

        foreach (range(1, 80) as $i) {
            $claimed = $i % 3 === 0;
            $year = fake()->numberBetween(2021, 2026);

            AlumniProfile::factory()->create([
                'student_number' => sprintf('DEMO/%d/%03d', $year - 3, $i),
                'programme_id' => $programmes->random()->id,
                'graduation_year' => $year,
                'graduation_date' => "{$year}-06-30",
                'class_of_award' => fake()->randomElement(['First Class', 'Second Class Upper', 'Second Class Lower']),
                'verification_status' => $claimed ? VerificationStatus::Verified : VerificationStatus::Unclaimed,
                'claimed_at' => $claimed ? now()->subDays(fake()->numberBetween(1, 200)) : null,
                'email' => $claimed ? fake()->unique()->userName().'@example.test' : null,
                'phone' => $claimed ? '+2567'.fake()->numerify('########') : null,
                'city' => $claimed ? fake()->randomElement(['Soroti', 'Kampala', 'Mbale', 'Gulu']) : null,
                'country' => $claimed ? 'Uganda' : null,
                'employment_status' => $claimed ? fake()->randomElement($statuses) : null,
                'further_study_status' => $claimed ? fake()->randomElement(FurtherStudyStatus::cases()) : null,
                'profile_updated_at' => $claimed ? now()->subDays(fake()->numberBetween(1, 700)) : null,
            ]);
        }

        // A few self-declared claims for the verification queue.
        foreach (range(1, 3) as $i) {
            AlumniProfile::factory()->pending()->create([
                'user_id' => User::factory()->alumnus()->create()->id,
                'student_number' => null,
                'declared_student_number' => sprintf('DEMO/2020/%03d', $i * 7),
                'programme_id' => $programmes->random()->id,
                'record_source' => RecordSource::SelfRegistered,
            ]);
        }
    }
}

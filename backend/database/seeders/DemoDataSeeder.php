<?php

namespace Database\Seeders;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use App\Enums\RecordSource;
use App\Enums\SurveyInvitationStatus;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyCycle;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
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

        $this->call([RoleSeeder::class, SurveyCycleSeeder::class]);

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

        $this->seedSurveyResponses();

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

    /**
     * Fake 12-month survey answers for most demo graduates, so the outcome dashboards have something
     * to draw. Outcomes are skewed a little differently per programme so the comparison is not flat.
     */
    private function seedSurveyResponses(): void
    {
        $cycle = TracerSurveyCycle::query()->where('milestone_months', 12)->first();
        if (! $cycle) {
            return;
        }

        $mixes = [
            // employed, self_employed, unemployed, further_study, other
            [55, 15, 18, 8, 4],
            [40, 25, 20, 10, 5],
            [62, 10, 14, 10, 4],
            [35, 20, 30, 10, 5],
        ];
        $categories = ['employed', 'self_employed', 'unemployed', 'further_study', 'other'];

        AlumniProfile::query()
            ->where('verification_status', '!=', VerificationStatus::Pending)
            ->whereNotNull('student_number')
            ->get()
            ->filter(fn () => fake()->boolean(70))
            ->each(function (AlumniProfile $profile) use ($cycle, $mixes, $categories) {
                $weights = $mixes[($profile->programme_id ?? 0) % count($mixes)];
                $roll = fake()->numberBetween(1, array_sum($weights));
                $employment = $categories[0];
                foreach ($weights as $i => $weight) {
                    if (($roll -= $weight) <= 0) {
                        $employment = $categories[$i];
                        break;
                    }
                }

                $invitation = SurveyInvitation::query()->create([
                    'tracer_survey_cycle_id' => $cycle->id, 'alumni_profile_id' => $profile->id, 'token' => Str::random(40),
                    'status' => SurveyInvitationStatus::Completed, 'due_at' => now()->subMonths(3), 'expires_at' => now()->subMonth(),
                    'sent_at' => now()->subMonths(3), 'completed_at' => now()->subMonths(2),
                ]);

                SurveyResponse::query()->create([
                    'survey_invitation_id' => $invitation->id, 'tracer_survey_version_id' => $cycle->current_version_id,
                    'alumni_profile_id' => $profile->id, 'submission_id' => (string) Str::uuid(), 'answers' => ['current_activity' => $employment],
                    'employment_status' => $employment,
                    'further_study_status' => fake()->randomElement(['none', 'none', 'none', 'studying', 'planned']),
                    'started_business' => $employment === 'self_employed' ? true : fake()->boolean(12),
                    'submitted_at' => now()->subMonths(2),
                ]);
            });
    }
}

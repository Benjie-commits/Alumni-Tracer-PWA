<?php

namespace Database\Seeders;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use App\Enums\JobType;
use App\Enums\RecordSource;
use App\Enums\SurveyInvitationStatus;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\EventPosting;
use App\Models\JobPosting;
use App\Models\Programme;
use App\Models\School;
use App\Models\Student;
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
            // Soroti University's real schools and programmes (the people in this seeder are still invented).
            'School of Health Sciences' => [
                'Medicine and Surgery' => ['Bachelor of Medicine and Bachelor of Surgery (MBChB)'],
                'Nursing' => ['Bachelor of Nursing Science (BNS)'],
                'Allied Health Sciences' => ['Bachelor of Science in Physiotherapy', 'Bachelor of Medical Laboratory Sciences (BMLS)'],
            ],
            'School of Engineering and Technology' => [
                'Electrical and Computer Engineering' => ['B.Eng. Electronics & Computer Engineering', 'B.Eng. Electrical & Electronics Engineering'],
            ],
            'School of Applied Science & Science Education' => [
                'Accounting, Finance and Computing' => ['Bachelor of Science in Accounting, Finance, and Computing'],
            ],
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
                'student_number' => sprintf('%02d01600%03d', ($year - 3) % 100, $i),
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
                'declared_student_number' => sprintf('2001600%03d', $i * 7),
                'programme_id' => $programmes->random()->id,
                'record_source' => RecordSource::SelfRegistered,
            ]);
        }

        $this->seedRegistrableRecords($programmes->first());
        $this->seedEngagement($programmes);
    }

    /**
     * Two unclaimed records whose Registrar data includes contact details, so the registration code step
     * (FR-2) can be shown. In development the code is not sent anywhere: with SMS_DRIVER=log and
     * MAIL_MAILER=log it is written to storage/logs/laravel.log.
     */
    private function seedRegistrableRecords(Programme $programme): void
    {
        $records = [
            ['2101600901', 'Grace', 'Akello', ['phone' => '+256700000901']],
            ['2101600902', 'Peter', 'Ojok', ['email' => 'peter.ojok@example.test']],
        ];

        foreach ($records as [$number, $first, $last, $contact]) {
            AlumniProfile::query()->updateOrCreate(['student_number' => $number], $contact + [
                'first_name' => $first,
                'last_name' => $last,
                'programme_id' => $programme->id,
                'graduation_year' => 2024,
                'graduation_date' => '2024-06-30',
                'class_of_award' => 'Second Class Upper',
                'verification_status' => VerificationStatus::Unclaimed,
                'record_source' => RecordSource::RegistrarImport,
            ]);
        }

        $this->command?->info('Demo records you can register against (student number / surname / year 2024): 2101600901 Akello (code by WhatsApp/SMS log), 2101600902 Ojok (code by email log).');
    }

    /** FR-5 sample content: a few jobs and events aimed at different groups, and current students. */
    private function seedEngagement($programmes): void
    {
        $first = $programmes->first();

        JobPosting::factory()->create(['title' => 'Graduate trainee (DEMO)', 'organisation' => 'DEMO Agro Ltd']);
        JobPosting::factory()->create([
            'title' => 'Laboratory internship (DEMO)', 'organisation' => 'DEMO Regional Hospital', 'type' => JobType::Internship,
            'programme_id' => $first->id, 'how_to_apply' => 'https://example.org/apply',
        ]);
        JobPosting::factory()->draft()->create(['title' => 'Draft posting (DEMO)', 'organisation' => 'DEMO Co']);

        EventPosting::factory()->create(['title' => 'DEMO Alumni homecoming']);
        EventPosting::factory()->create([
            'title' => 'DEMO Career talk for recent graduates', 'graduation_year_from' => 2023,
            'venue' => null, 'online_url' => 'https://example.org/live',
        ]);

        foreach (range(1, 4) as $i) {
            Student::factory()->create([
                'student_number' => sprintf('2401600%03d', $i),
                'programme_id' => $programmes->random()->id,
                'contact_shared_at' => $i % 2 === 0 ? now() : null,
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

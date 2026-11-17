<?php

namespace Tests\Feature\Engagement;

use App\Enums\MentorshipStatus;
use App\Enums\VerificationStatus;
use App\Livewire\Admin\EventPostings;
use App\Livewire\Admin\JobPostings;
use App\Livewire\Admin\Mentorship;
use App\Models\ActivityLog;
use App\Models\AlumniProfile;
use App\Models\EventPosting;
use App\Models\JobPosting;
use App\Models\MentorshipMatch;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-5: event postings, job/internship postings targeted to alumni segments, and mentorship matching. */
class EngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /** @return array{0: User, 1: AlumniProfile} */
    private function alumnus(array $profile = []): array
    {
        $user = User::factory()->alumnus()->create();
        $record = AlumniProfile::factory()->claimedBy($user)->create($profile + [
            'programme_id' => Programme::factory(),
            'graduation_year' => 2022,
        ]);

        return [$user, $record];
    }

    private function jobTitles(User $user): array
    {
        return collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/me/jobs')->assertOk()->json('data'))->pluck('title')->all();
    }

    // ---- targeting ----------------------------------------------------------------------------

    public function test_a_job_aimed_at_a_programme_is_seen_only_by_that_programmes_alumni(): void
    {
        [$biologist, $profile] = $this->alumnus();
        [$other] = $this->alumnus();

        JobPosting::factory()->create(['title' => 'For everyone']);
        JobPosting::factory()->create(['title' => 'Biology only', 'programme_id' => $profile->programme_id]);

        $this->assertEqualsCanonicalizing(['For everyone', 'Biology only'], $this->jobTitles($biologist));
        $this->assertSame(['For everyone'], $this->jobTitles($other));
    }

    public function test_school_department_and_year_targets_all_apply(): void
    {
        [$user, $profile] = $this->alumnus(['graduation_year' => 2022]);
        $profile->load('programme.department');

        JobPosting::factory()->create(['title' => 'My school', 'school_id' => $profile->programme->department->school_id]);
        JobPosting::factory()->create(['title' => 'My department', 'department_id' => $profile->programme->department_id]);
        JobPosting::factory()->create(['title' => 'Class of 2020-2023', 'graduation_year_from' => 2020, 'graduation_year_to' => 2023]);
        JobPosting::factory()->create(['title' => 'Class of 2024 on', 'graduation_year_from' => 2024]);
        JobPosting::factory()->create(['title' => 'My school, but 2024 on', 'school_id' => $profile->programme->department->school_id, 'graduation_year_from' => 2024]);

        $this->assertEqualsCanonicalizing(['My school', 'My department', 'Class of 2020-2023'], $this->jobTitles($user));
    }

    public function test_drafts_and_closed_jobs_are_not_shown(): void
    {
        [$user] = $this->alumnus();

        JobPosting::factory()->create(['title' => 'Open']);
        JobPosting::factory()->draft()->create(['title' => 'Draft']);
        JobPosting::factory()->create(['title' => 'Closed', 'closes_on' => now()->subDays(2)->toDateString()]);
        JobPosting::factory()->create(['title' => 'No closing date', 'closes_on' => null]);

        $this->assertEqualsCanonicalizing(['Open', 'No closing date'], $this->jobTitles($user));
    }

    public function test_only_upcoming_published_events_aimed_at_the_alumnus_are_shown(): void
    {
        [$user, $profile] = $this->alumnus();

        EventPosting::factory()->create(['title' => 'Homecoming']);
        EventPosting::factory()->create(['title' => 'Last year', 'starts_at' => now()->subYear(), 'ends_at' => now()->subYear()->addHours(2)]);
        EventPosting::factory()->create(['title' => 'Other programme', 'programme_id' => Programme::factory()]);
        EventPosting::factory()->create(['title' => 'Draft', 'published_at' => null]);

        $titles = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/me/events')->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['Homecoming'], $titles);
    }

    public function test_engagement_endpoints_need_an_alumni_sign_in(): void
    {
        $this->getJson('/api/v1/me/jobs')->assertUnauthorized();
        $this->getJson('/api/v1/me/events')->assertUnauthorized();
        $this->actingAs(User::factory()->registrar()->create(), 'sanctum')->getJson('/api/v1/me/jobs')->assertForbidden();
    }

    // ---- staff screens ------------------------------------------------------------------------

    public function test_a_registrar_posts_a_targeted_job_that_reaches_the_right_alumni(): void
    {
        [$user, $profile] = $this->alumnus();
        $registrar = User::factory()->registrar()->create();

        Livewire::actingAs($registrar)->test(JobPostings::class)
            ->set('title', 'Lab technician')
            ->set('organisation', 'Soroti Regional Hospital')
            ->set('type', 'job')
            ->set('description', 'Full-time role.')
            ->set('howToApply', 'hr@example.com')
            ->set('target', 'programme:'.$profile->programme_id)
            ->call('save')
            ->assertHasNoErrors();

        $job = JobPosting::sole();
        $this->assertSame($registrar->id, $job->created_by);
        $this->assertNotNull($job->published_at);
        $this->assertSame(['Lab technician'], $this->jobTitles($user));
    }

    public function test_job_form_validation(): void
    {
        Livewire::actingAs(User::factory()->registrar()->create())->test(JobPostings::class)
            ->set('title', '')
            ->set('yearFrom', '2024')
            ->set('yearTo', '2020')
            ->call('save')
            ->assertHasErrors(['title', 'organisation', 'description', 'howToApply']);

        Livewire::actingAs(User::factory()->registrar()->create())->test(JobPostings::class)
            ->set('title', 'T')->set('organisation', 'O')->set('description', 'D')->set('howToApply', 'A')
            ->set('yearFrom', '2024')->set('yearTo', '2020')
            ->call('save')
            ->assertHasErrors(['yearTo']);

        $this->assertSame(0, JobPosting::count());
    }

    public function test_an_event_needs_a_venue_or_a_link_and_must_end_after_it_starts(): void
    {
        Livewire::actingAs(User::factory()->registrar()->create())->test(EventPostings::class)
            ->set('title', 'Career fair')->set('description', 'D')
            ->set('startsAt', now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('endsAt', now()->addDays(6)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasErrors(['venue', 'endsAt']);
    }

    public function test_qa_viewers_cannot_manage_engagement(): void
    {
        $qa = User::factory()->qaViewer()->create();

        foreach (['admin.jobs', 'admin.events', 'admin.mentorship'] as $route) {
            $this->actingAs($qa)->get(route($route))->assertForbidden();
        }
        Livewire::actingAs($qa)->test(Mentorship::class)->assertForbidden();
    }

    // ---- mentorship ---------------------------------------------------------------------------

    public function test_a_verified_alumnus_can_offer_to_mentor_and_a_pending_one_cannot(): void
    {
        [$verified] = $this->alumnus();
        [$pending] = $this->alumnus(['verification_status' => VerificationStatus::Pending]);

        $this->actingAs($verified, 'sanctum')->putJson('/api/v1/me/mentoring', ['opted_in' => true, 'topics' => 'Careers in health'])
            ->assertOk()
            ->assertJsonPath('data.opted_in', true)
            ->assertJsonPath('data.topics', 'Careers in health');

        $this->actingAs($pending, 'sanctum')->putJson('/api/v1/me/mentoring', ['opted_in' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opted_in');
    }

    public function test_staff_can_only_match_students_with_alumni_who_offered(): void
    {
        [, $volunteer] = $this->alumnus(['mentor_opt_in_at' => now()]);
        [, $notOffered] = $this->alumnus();
        $student = Student::factory()->create();
        $registrar = User::factory()->registrar()->create();

        Livewire::actingAs($registrar)->test(Mentorship::class)
            ->set('matchStudentId', (string) $student->id)
            ->set('matchMentorId', (string) $notOffered->id)
            ->call('match')
            ->assertHasErrors(['matchMentorId']);

        Livewire::actingAs($registrar)->test(Mentorship::class)
            ->set('matchStudentId', (string) $student->id)
            ->set('matchMentorId', (string) $volunteer->id)
            ->set('focus', 'Getting a first job')
            ->call('match')
            ->assertHasNoErrors();

        $match = MentorshipMatch::sole();
        $this->assertSame($volunteer->id, $match->alumni_profile_id);
        $this->assertSame(MentorshipStatus::Active, $match->status);
        $this->assertSame('mentorship.created', ActivityLog::query()->latest('id')->value('action'));
    }

    public function test_a_student_cannot_have_two_mentors_at_once(): void
    {
        [, $first] = $this->alumnus(['mentor_opt_in_at' => now()]);
        [, $second] = $this->alumnus(['mentor_opt_in_at' => now()]);
        $student = Student::factory()->create();
        MentorshipMatch::query()->create(['alumni_profile_id' => $first->id, 'student_id' => $student->id, 'status' => MentorshipStatus::Active, 'started_at' => now()]);

        Livewire::actingAs(User::factory()->registrar()->create())->test(Mentorship::class)
            ->set('matchStudentId', (string) $student->id)
            ->set('matchMentorId', (string) $second->id)
            ->call('match')
            ->assertHasErrors(['matchStudentId']);
    }

    public function test_the_mentor_sees_the_student_and_contact_details_only_with_consent(): void
    {
        [$user, $mentor] = $this->alumnus(['mentor_opt_in_at' => now()]);
        $private = Student::factory()->create(['first_name' => 'Private', 'email' => 'private@example.com']);
        $shared = Student::factory()->create(['first_name' => 'Shared', 'email' => 'shared@example.com', 'contact_shared_at' => now()]);
        foreach ([$private, $shared] as $student) {
            MentorshipMatch::query()->create(['alumni_profile_id' => $mentor->id, 'student_id' => $student->id, 'status' => MentorshipStatus::Active, 'started_at' => now()]);
        }

        $mentees = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/me/mentoring')->assertOk()->json('data.mentees'))->keyBy(fn ($m) => explode(' ', $m['name'])[0]);

        $this->assertNull($mentees['Private']['email']);
        $this->assertNull($mentees['Private']['phone']);
        $this->assertSame('shared@example.com', $mentees['Shared']['email']);
    }

    public function test_an_ended_mentorship_disappears_from_the_mentors_app(): void
    {
        [$user, $mentor] = $this->alumnus(['mentor_opt_in_at' => now()]);
        $match = MentorshipMatch::query()->create([
            'alumni_profile_id' => $mentor->id, 'student_id' => Student::factory()->create()->id,
            'status' => MentorshipStatus::Active, 'started_at' => now(),
        ]);

        Livewire::actingAs(User::factory()->registrar()->create())->test(Mentorship::class)->call('endMatch', $match->id);

        $this->assertSame(MentorshipStatus::Ended, $match->fresh()->status);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/mentoring')->assertJsonCount(0, 'data.mentees');
    }
}

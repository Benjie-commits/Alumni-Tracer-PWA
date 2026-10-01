<?php

namespace Tests\Feature\Phase3;

use App\Enums\SurveyInvitationStatus;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyCycle;
use App\Services\Outcomes\OutcomeFilters;
use App\Services\Outcomes\OutcomeReport;
use Illuminate\Support\Str;

class OutcomeReportTest extends Phase3TestCase
{
    private function report(array $filters = []): array
    {
        return app(OutcomeReport::class)->build(OutcomeFilters::fromArray($filters));
    }

    /** @return array<string, mixed> the row for a group label */
    private function row(array $report, string $label): array
    {
        $row = collect($report['rows'])->firstWhere('label', $label);
        $this->assertNotNull($row, "no row labelled {$label}; have: ".collect($report['rows'])->pluck('label')->implode(', '));

        return $row;
    }

    private function programmeIn(string $school, string $programme, string $department = 'Department'): Programme
    {
        $s = School::firstOrCreate(['name' => $school]);
        $d = Department::firstOrCreate(['school_id' => $s->id, 'name' => $department]);

        return Programme::firstOrCreate(['department_id' => $d->id, 'name' => $programme]);
    }

    /** One graduate who answered the survey at `$months`. */
    private function respondent(Programme $programme, string $employment, array $profile = [], array $response = [], int $months = 12, ?string $at = null): AlumniProfile
    {
        $graduate = $this->graduate($profile + ['programme_id' => $programme->id, 'student_number' => 'SU/'.Str::random(8)]);
        $this->answer($graduate, $employment, $response, $months, $at);

        return $graduate;
    }

    private function answer(AlumniProfile $graduate, string $employment, array $response = [], int $months = 12, ?string $at = null): SurveyResponse
    {
        $cycle = TracerSurveyCycle::where('milestone_months', $months)->firstOrFail();
        $invitation = SurveyInvitation::create([
            'tracer_survey_cycle_id' => $cycle->id, 'alumni_profile_id' => $graduate->id, 'token' => Str::random(40),
            'status' => SurveyInvitationStatus::Completed, 'due_at' => now()->subMonth(), 'expires_at' => now()->addMonth(), 'completed_at' => now(),
        ]);

        return SurveyResponse::create($response + [
            'survey_invitation_id' => $invitation->id, 'tracer_survey_version_id' => $cycle->current_version_id,
            'alumni_profile_id' => $graduate->id, 'submission_id' => (string) Str::uuid(), 'answers' => [],
            'employment_status' => $employment, 'further_study_status' => 'none', 'started_business' => false,
            'submitted_at' => $at ?? now(),
        ]);
    }

    /** Several respondents at once: ['employed' => 5, 'unemployed' => 2]. */
    private function cohort(Programme $programme, array $outcomes, array $profile = [], int $months = 12): void
    {
        foreach ($outcomes as $employment => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->respondent($programme, $employment, $profile, months: $months);
            }
        }
    }

    // ---- the numbers -----------------------------------------------------------------------

    public function test_counts_and_percentages_are_worked_out_per_school(): void
    {
        $biology = $this->programmeIn('School of Science', 'BSc Biology');
        $this->cohort($biology, ['employed' => 5, 'self_employed' => 2, 'unemployed' => 2, 'further_study' => 1]);

        $row = $this->row($this->report(), 'School of Science');

        $this->assertSame(10, $row['n']);
        $this->assertSame(['employed' => 5, 'self_employed' => 2, 'unemployed' => 2, 'further_study' => 1, 'other' => 0], $row['counts']);
        $this->assertSame(50.0, $row['pct']['employed']);
        $this->assertSame(20.0, $row['pct']['self_employed']);
        $this->assertSame(10.0, $row['pct']['further_study']);
        $this->assertSame(70.0, $row['in_work_pct'], 'employed plus self-employed');
        $this->assertFalse($row['suppressed']);
    }

    public function test_further_study_and_entrepreneurship_are_reported_alongside(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        foreach (range(1, 4) as $i) {
            $this->respondent($p, 'employed', response: ['further_study_status' => 'studying', 'started_business' => true]);
        }
        $this->respondent($p, 'employed', response: ['further_study_status' => 'planned', 'started_business' => false]);
        foreach (range(1, 5) as $i) {
            $this->respondent($p, 'unemployed', response: ['further_study_status' => 'none', 'started_business' => false]);
        }

        $row = $this->row($this->report(), 'School of Science');

        $this->assertSame(10, $row['n']);
        $this->assertSame(50.0, $row['further_study_pct'], 'studying now (4) or planning to (1) is 5 of 10');
        $this->assertSame(40.0, $row['business_pct'], '4 of the 10 started a business');
    }

    public function test_the_business_figure_is_based_on_those_who_were_asked(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        foreach (range(1, 5) as $i) {
            $this->respondent($p, 'employed', response: ['started_business' => true]);
        }
        foreach (range(1, 5) as $i) {
            $this->respondent($p, 'employed', response: ['started_business' => null]); // never answered
        }

        $this->assertSame(100.0, $this->row($this->report(), 'School of Science')['business_pct'], '5 of the 5 who answered, not 5 of 10');
    }

    public function test_the_summary_covers_everyone_in_the_view(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 6]);
        $this->cohort($this->programmeIn('School of Arts', 'BA History'), ['unemployed' => 4, 'employed' => 2]);

        $summary = $this->report()['summary'];

        $this->assertSame(12, $summary['n']);
        $this->assertSame(8, $summary['counts']['employed']);
        $this->assertSame(4, $summary['counts']['unemployed']);
    }

    // ---- small groups are withheld -----------------------------------------------------------

    public function test_a_group_below_the_threshold_is_hidden_and_one_at_it_is_shown(): void
    {
        $this->cohort($this->programmeIn('Tiny School', 'Tiny Programme'), ['employed' => 4]);   // 4: hidden
        $this->cohort($this->programmeIn('Edge School', 'Edge Programme'), ['employed' => 5]);   // 5: shown

        $report = $this->report();
        $tiny = $this->row($report, 'Tiny School');
        $edge = $this->row($report, 'Edge School');

        $this->assertTrue($tiny['suppressed']);
        $this->assertNull($tiny['in_work_pct']);
        $this->assertSame([null, null, null, null, null], array_values($tiny['pct']));
        $this->assertNull($tiny['further_study_pct']);
        $this->assertNull($tiny['business_pct']);

        $this->assertFalse($edge['suppressed']);
        $this->assertSame(100.0, $edge['in_work_pct']);
    }

    public function test_the_whole_view_is_withheld_when_it_is_too_small(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 2, 'unemployed' => 1]);

        $summary = $this->report()['summary'];

        $this->assertSame(3, $summary['n']);
        $this->assertTrue($summary['suppressed']);
        $this->assertNull($summary['in_work_pct']);
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['sunates.dashboards.min_cell_size' => 10]);
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 7]);

        $this->assertTrue($this->row($this->report(), 'School of Science')['suppressed']);
        $this->assertSame(10, $this->report()['min']);

        config(['sunates.dashboards.min_cell_size' => 3]);
        $this->assertFalse($this->row($this->report(), 'School of Science')['suppressed']);
    }

    public function test_narrowing_the_view_to_one_small_programme_cannot_expose_it(): void
    {
        $small = $this->programmeIn('School of Science', 'Tiny Programme');
        $this->cohort($small, ['employed' => 2]);
        $this->cohort($this->programmeIn('School of Science', 'Big Programme'), ['unemployed' => 8]);

        $filtered = $this->report(['programmeId' => $small->id]);

        $this->assertTrue($filtered['summary']['suppressed']);
        $this->assertNull($filtered['summary']['in_work_pct']);
    }

    // ---- people, not responses -----------------------------------------------------------------

    public function test_by_default_each_alumnus_counts_once_with_their_latest_answer(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        // Five people answered at 6 months (employed) and again at a year (now seeking work).
        foreach (range(1, 5) as $i) {
            $graduate = $this->graduate(['programme_id' => $p->id, 'student_number' => 'SU/'.Str::random(8)]);
            $this->answer($graduate, 'employed', months: 6, at: '2026-04-01 10:00:00');
            $this->answer($graduate, 'unemployed', months: 12, at: '2026-10-01 10:00:00');
        }

        $row = $this->row($this->report(), 'School of Science');

        $this->assertSame(5, $row['n'], 'five people, not ten responses');
        $this->assertSame(5, $row['counts']['unemployed']);
        $this->assertSame(0, $row['counts']['employed']);
    }

    public function test_a_survey_can_be_chosen_to_compare_like_with_like(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        foreach (range(1, 5) as $i) {
            $graduate = $this->graduate(['programme_id' => $p->id, 'student_number' => 'SU/'.Str::random(8)]);
            $this->answer($graduate, 'employed', months: 6);
            $this->answer($graduate, 'unemployed', months: 12);
        }

        $sixMonths = $this->row($this->report(['milestone' => 6]), 'School of Science');
        $oneYear = $this->row($this->report(['milestone' => 12]), 'School of Science');

        $this->assertSame(5, $sixMonths['counts']['employed']);
        $this->assertSame(5, $oneYear['counts']['unemployed']);
    }

    // ---- filters and grouping ---------------------------------------------------------------------

    public function test_filters_narrow_by_school_department_programme_and_graduation_year(): void
    {
        $bio = $this->programmeIn('School of Science', 'BSc Biology', 'Biology');
        $chem = $this->programmeIn('School of Science', 'BSc Chemistry', 'Chemistry');
        $history = $this->programmeIn('School of Arts', 'BA History', 'History');
        $this->cohort($bio, ['employed' => 5], ['graduation_year' => 2023]);
        $this->cohort($bio, ['unemployed' => 5], ['graduation_year' => 2024]);
        $this->cohort($chem, ['employed' => 5], ['graduation_year' => 2024]);
        $this->cohort($history, ['other' => 5], ['graduation_year' => 2024]);

        $scienceId = School::where('name', 'School of Science')->value('id');

        $this->assertSame(15, $this->report(['schoolId' => $scienceId])['summary']['n']);
        $this->assertSame(10, $this->report(['departmentId' => $bio->department_id])['summary']['n']);
        $this->assertSame(5, $this->report(['programmeId' => $chem->id])['summary']['n']);
        $this->assertSame(15, $this->report(['yearFrom' => 2024])['summary']['n']);
        $this->assertSame(5, $this->report(['yearTo' => 2023])['summary']['n']);
        $this->assertSame(5, $this->report(['schoolId' => $scienceId, 'yearFrom' => 2024, 'yearTo' => 2024, 'departmentId' => $chem->department_id])['summary']['n']);
    }

    public function test_grouping_by_department_programme_and_year(): void
    {
        $bio = $this->programmeIn('School of Science', 'BSc Biology', 'Biology');
        $chem = $this->programmeIn('School of Science', 'BSc Chemistry', 'Chemistry');
        $this->cohort($bio, ['employed' => 6], ['graduation_year' => 2023]);
        $this->cohort($chem, ['employed' => 5], ['graduation_year' => 2024]);

        $byDepartment = $this->report(['groupBy' => 'department']);
        $this->assertSame(['School of Science › Biology', 'School of Science › Chemistry'], collect($byDepartment['rows'])->pluck('label')->all());

        $byProgramme = $this->report(['groupBy' => 'programme']);
        $this->assertSame(['BSc Biology', 'BSc Chemistry'], collect($byProgramme['rows'])->pluck('label')->all(), 'largest first');

        $byYear = $this->report(['groupBy' => 'year']);
        $this->assertSame(['2024', '2023'], collect($byYear['rows'])->pluck('label')->all(), 'newest cohort first');
    }

    public function test_graduates_with_no_programme_are_grouped_as_not_assigned(): void
    {
        foreach (range(1, 5) as $i) {
            $graduate = $this->graduate(['programme_id' => null, 'student_number' => 'SU/'.Str::random(8)]);
            $this->answer($graduate, 'employed');
        }

        $this->assertSame(5, $this->row($this->report(['groupBy' => 'programme']), 'Not assigned')['n']);
    }

    public function test_deleted_records_are_left_out(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        $this->cohort($p, ['employed' => 5]);
        $this->respondent($p, 'unemployed')->delete();

        $this->assertSame(5, $this->report()['summary']['n']);
    }

    // ---- response rate ---------------------------------------------------------------------------------

    public function test_the_response_rate_counts_only_surveys_that_have_closed(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        $cycle = TracerSurveyCycle::where('milestone_months', 12)->first();
        $this->cohort($p, ['employed' => 6]); // 6 completed
        foreach ([SurveyInvitationStatus::Expired, SurveyInvitationStatus::Expired] as $status) { // 2 missed
            SurveyInvitation::create([
                'tracer_survey_cycle_id' => $cycle->id, 'alumni_profile_id' => $this->graduate(['programme_id' => $p->id, 'student_number' => 'SU/'.Str::random(8)])->id,
                'token' => Str::random(40), 'status' => $status, 'due_at' => now()->subMonths(5), 'expires_at' => now()->subMonths(2),
            ]);
        }
        foreach ([SurveyInvitationStatus::Sent, SurveyInvitationStatus::Scheduled] as $status) { // still open: not counted
            SurveyInvitation::create([
                'tracer_survey_cycle_id' => $cycle->id, 'alumni_profile_id' => $this->graduate(['programme_id' => $p->id, 'student_number' => 'SU/'.Str::random(8)])->id,
                'token' => Str::random(40), 'status' => $status, 'due_at' => now(), 'expires_at' => now()->addMonth(),
            ]);
        }

        $summary = $this->report()['summary'];

        $this->assertSame(6, $summary['rate_done']);
        $this->assertSame(8, $summary['rate_of'], '6 answered + 2 expired; the 2 still open are not "missed" yet');
        $this->assertSame(75.0, $summary['rate_pct']);
    }

    // ---- the other data source ---------------------------------------------------------------------------

    private function recorded(Programme $programme, ?string $employment, array $attributes = []): AlumniProfile
    {
        return $this->graduate($attributes + ['programme_id' => $programme->id, 'employment_status' => $employment, 'student_number' => 'SU/'.Str::random(8)]);
    }

    public function test_the_profile_source_reports_the_latest_status_alumni_recorded_themselves(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        foreach (range(1, 4) as $i) {
            $this->recorded($p, 'employed', ['further_study_status' => 'studying']);
        }
        $this->recorded($p, 'unemployed');
        foreach (range(1, 5) as $i) {
            $this->recorded($p, null); // never recorded anything: counted in coverage, not in outcomes
        }

        $row = $this->row($this->report(['source' => 'profiles']), 'School of Science');

        $this->assertSame(5, $row['n']);
        $this->assertSame(80.0, $row['in_work_pct']);
        $this->assertSame(80.0, $row['further_study_pct']);
        $this->assertNull($row['business_pct'], 'profiles do not record "started a business"');
        $this->assertSame(5, $row['rate_done']);
        $this->assertSame(10, $row['rate_of']);
        $this->assertSame(50.0, $row['rate_pct'], 'coverage: half of the graduate records have a status');
    }

    public function test_the_profile_source_only_counts_graduates_the_registrar_holds(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        foreach (range(1, 5) as $i) {
            $this->recorded($p, 'employed');
        }
        $this->recorded($p, 'employed', ['verification_status' => VerificationStatus::Pending]);
        $this->recorded($p, 'employed', ['verification_status' => VerificationStatus::Rejected]);
        $this->recorded($p, 'employed', ['student_number' => null, 'declared_student_number' => 'SU/X/1']);
        $this->recorded($p, 'employed', ['graduation_year' => null]);

        $this->assertSame(5, $this->report(['source' => 'profiles'])['summary']['n']);
    }

    public function test_the_two_sources_do_not_mix(): void
    {
        $p = $this->programmeIn('School of Science', 'BSc Biology');
        $this->cohort($p, ['employed' => 5]); // survey answers (their profiles carry no status of their own)

        $this->assertSame(5, $this->report()['summary']['n']);
        $this->assertSame(0, $this->report(['source' => 'profiles'])['summary']['n']);
    }

    // ---- input handling -------------------------------------------------------------------------------------

    public function test_filter_input_is_cleaned_so_a_hand_edited_url_cannot_misbehave(): void
    {
        $f = OutcomeFilters::fromArray([
            'source' => 'hackers', 'milestone' => '7', 'groupBy' => 'password', 'schoolId' => '1; DROP TABLE x', 'programmeId' => '-4',
            'yearFrom' => 'abc', 'yearTo' => ['x'], 'departmentId' => '',
        ]);

        $this->assertSame('surveys', $f->source);
        $this->assertNull($f->milestone);
        $this->assertSame('school', $f->groupBy);
        $this->assertNull($f->schoolId);
        $this->assertNull($f->programmeId);
        $this->assertNull($f->yearFrom);
        $this->assertNull($f->yearTo);
        $this->assertNull($f->departmentId);
        $this->report(['source' => 'hackers', 'groupBy' => 'password', 'schoolId' => '1; DROP TABLE x']); // must simply run
    }

    public function test_a_milestone_only_applies_to_surveys(): void
    {
        $this->assertSame(12, OutcomeFilters::fromArray(['milestone' => '12'])->milestone);
        $this->assertNull(OutcomeFilters::fromArray(['source' => 'profiles', 'milestone' => '12'])->milestone);
    }

    public function test_the_query_string_form_omits_defaults(): void
    {
        $this->assertSame([], OutcomeFilters::fromArray([])->toQuery());
        $this->assertSame(
            ['source' => 'profiles', 'schoolId' => 3, 'yearFrom' => 2022, 'groupBy' => 'year'],
            OutcomeFilters::fromArray(['source' => 'profiles', 'schoolId' => '3', 'yearFrom' => '2022', 'groupBy' => 'year'])->toQuery(),
        );
    }

    public function test_an_empty_database_gives_an_empty_but_valid_report(): void
    {
        $report = $this->report();

        $this->assertSame(0, $report['summary']['n']);
        $this->assertSame([], $report['rows']);
        $this->assertTrue($report['summary']['suppressed']);
    }
}

<?php

namespace Tests\Feature\Phase3;

use App\Enums\SurveyInvitationStatus;
use App\Livewire\Admin\Outcomes;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyCycle;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

class OutcomesPageTest extends Phase3TestCase
{
    private function programmeIn(string $school, string $programme, string $department = 'Department'): Programme
    {
        $s = School::firstOrCreate(['name' => $school]);
        $d = Department::firstOrCreate(['school_id' => $s->id, 'name' => $department]);

        return Programme::firstOrCreate(['department_id' => $d->id, 'name' => $programme]);
    }

    /** @param  array<string, int>  $outcomes  e.g. ['employed' => 5, 'unemployed' => 2] */
    private function cohort(Programme $programme, array $outcomes, array $profile = []): void
    {
        $cycle = TracerSurveyCycle::where('milestone_months', 12)->first();

        foreach ($outcomes as $employment => $count) {
            for ($i = 0; $i < $count; $i++) {
                $graduate = $this->graduate($profile + [
                    'programme_id' => $programme->id, 'student_number' => 'SU/'.Str::random(8),
                    'first_name' => 'Secretfirst'.$i, 'last_name' => 'Secretlast', 'email' => "private{$i}@example.com",
                ]);
                $invitation = SurveyInvitation::create([
                    'tracer_survey_cycle_id' => $cycle->id, 'alumni_profile_id' => $graduate->id, 'token' => Str::random(40),
                    'status' => SurveyInvitationStatus::Completed, 'due_at' => now()->subMonth(), 'expires_at' => now()->addMonth(),
                ]);
                SurveyResponse::create([
                    'survey_invitation_id' => $invitation->id, 'tracer_survey_version_id' => $cycle->current_version_id,
                    'alumni_profile_id' => $graduate->id, 'submission_id' => (string) Str::uuid(), 'answers' => ['employer_name' => 'Hidden Employer Ltd'],
                    'employment_status' => $employment, 'further_study_status' => 'none', 'started_business' => false, 'submitted_at' => now(),
                ]);
            }
        }
    }

    private function staff(string $role = 'registrar'): User
    {
        return User::factory()->{$role}()->create();
    }

    // ---- access ------------------------------------------------------------------------------

    public function test_every_staff_role_can_read_the_dashboard_and_export_it(): void
    {
        foreach (['registrar', 'ictAdmin', 'qaViewer'] as $role) {
            $this->actingAs($this->staff($role))->get(route('admin.outcomes'))->assertOk()->assertSee('Graduate outcomes');
            $this->actingAs($this->staff($role))->get(route('admin.outcomes.export'))->assertOk();
        }
    }

    public function test_alumni_and_signed_out_visitors_cannot(): void
    {
        $this->actingAs(User::factory()->alumnus()->create())->get(route('admin.outcomes'))->assertForbidden();
        $this->actingAs(User::factory()->alumnus()->create())->get(route('admin.outcomes.export'))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.outcomes'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.outcomes.export'))->assertRedirect(route('admin.login'));
    }

    public function test_the_page_is_in_every_staff_members_navigation(): void
    {
        $this->actingAs($this->staff('qaViewer'))->get(route('admin.dashboard'))->assertSee('Graduate outcomes');
    }

    // ---- nothing personal ever appears ---------------------------------------------------------------

    public function test_the_page_and_the_export_never_contain_anyones_details(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 6]);
        $qa = $this->staff('qaViewer');

        $page = $this->actingAs($qa)->get(route('admin.outcomes'))->assertOk();
        $csv = $this->actingAs($qa)->get(route('admin.outcomes.export'))->streamedContent();

        foreach (['Secretfirst', 'Secretlast', 'private0@example.com', 'Hidden Employer Ltd', 'SU/'] as $secret) {
            $page->assertDontSee($secret);
            $this->assertStringNotContainsString($secret, $csv, "the export must not contain {$secret}");
        }
    }

    public function test_the_livewire_payload_carries_aggregates_not_individuals(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 6]);

        $html = Livewire::actingAs($this->staff('qaViewer'))->test(Outcomes::class)->html();

        $this->assertStringNotContainsString('Secretfirst', $html);
        $this->assertStringNotContainsString('private0@example.com', $html);
    }

    // ---- what the page shows -------------------------------------------------------------------------------

    public function test_headline_numbers_the_chart_and_the_table_agree(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 5, 'self_employed' => 2, 'unemployed' => 2, 'further_study' => 1]);

        $page = Livewire::actingAs($this->staff())->test(Outcomes::class);

        $page->assertSee('Graduates who answered')
            ->assertSee('70%')                   // in work: stat tile and table
            ->assertSee('Employment outcome by school')
            ->assertSee('Table view')
            ->assertSee('School of Science')
            ->assertSee('All graduates in this view')
            ->assertSeeHtml('data-tip="Employed: 50% (5 of 10)"')
            ->assertSeeHtml('data-tip="Self-employed: 20% (2 of 10)"')
            ->assertSeeHtml('tabindex="0"');     // segments are keyboard-reachable, not hover-only
    }

    public function test_every_bar_has_a_text_equivalent_for_screen_readers(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 5, 'unemployed' => 5]);

        Livewire::actingAs($this->staff())->test(Outcomes::class)
            ->assertSeeHtml('role="img"')
            ->assertSeeHtml('aria-label="School of Science: Employed 50%')
            ->assertSeeHtml('Seeking work 50%');
    }

    public function test_the_legend_names_every_category_so_colour_is_never_the_only_clue(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 5]);

        $page = Livewire::actingAs($this->staff())->test(Outcomes::class);

        foreach (['Employed', 'Self-employed', 'Seeking work', 'Studying full time', 'Other'] as $label) {
            $page->assertSee($label);
        }
        $page->assertSeeHtml('class="legend"');
    }

    public function test_small_groups_are_drawn_as_withheld_not_as_bars(): void
    {
        $this->cohort($this->programmeIn('Big School', 'Big'), ['employed' => 6]);
        $this->cohort($this->programmeIn('Tiny School', 'Tiny'), ['unemployed' => 3]);

        $page = Livewire::actingAs($this->staff())->test(Outcomes::class);

        $page->assertSee('Big School')->assertSee('Tiny School')
            ->assertSee('Fewer than 5 responses: not shown to protect privacy')
            ->assertSee('Fewer than 5 responses: not shown')
            ->assertDontSeeHtml('data-tip="Seeking work: 100% (3 of 3)"');
    }

    public function test_a_view_that_is_too_small_explains_why_there_are_no_percentages(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 3]);

        Livewire::actingAs($this->staff())->test(Outcomes::class)
            ->assertSee('fewer than 5')
            ->assertSee('percentages are not shown for groups this small');
    }

    public function test_with_no_survey_answers_yet_it_points_to_the_profile_data(): void
    {
        Livewire::actingAs($this->staff())->test(Outcomes::class)
            ->assertSee('No survey responses match this view yet')
            ->assertSee('Alumni profiles');
    }

    public function test_alumni_profile_data_can_be_viewed_before_any_survey_has_come_back(): void
    {
        $biology = $this->programmeIn('School of Science', 'BSc Biology');
        foreach (range(1, 6) as $i) {
            $this->graduate(['programme_id' => $biology->id, 'employment_status' => 'employed', 'student_number' => 'SU/'.Str::random(8)]);
        }

        Livewire::actingAs($this->staff())->test(Outcomes::class)
            ->assertSee('No survey responses')
            ->set('source', 'profiles')
            ->assertSee('Graduates with a known status')
            ->assertSee('School of Science')
            ->assertSee('Records with a known status')
            ->assertSee('not recorded on profiles')
            ->assertDontSee('No recorded statuses');
    }

    public function test_the_page_escapes_names_it_displays(): void
    {
        $this->cohort($this->programmeIn('<script>alert(1)</script> School', 'Programme'), ['employed' => 5]);

        $this->actingAs($this->staff())->get(route('admin.outcomes'))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    // ---- filters ------------------------------------------------------------------------------------------------

    public function test_filters_change_the_slice_and_dependent_ones_reset(): void
    {
        $bio = $this->programmeIn('School of Science', 'BSc Biology', 'Biology');
        $history = $this->programmeIn('School of Arts', 'BA History', 'History');
        $this->cohort($bio, ['employed' => 5]);
        $this->cohort($history, ['unemployed' => 5]);
        $science = School::where('name', 'School of Science')->value('id');

        $page = Livewire::actingAs($this->staff())->test(Outcomes::class);
        // The school drop-down always lists every school, so check the report's own rows, not the page text.
        $rows = fn () => collect($page->viewData('report')['rows'])->pluck('label')->sort()->values()->all();

        $this->assertSame(['School of Arts', 'School of Science'], $rows());

        $page->set('schoolId', (string) $science);
        $this->assertSame(['School of Science'], $rows());

        $page->set('departmentId', (string) $bio->department_id)->set('programmeId', (string) $bio->id)
            ->set('schoolId', '')
            ->assertSet('departmentId', '')
            ->assertSet('programmeId', '');
        $this->assertSame(['School of Arts', 'School of Science'], $rows());

        $page->set('yearFrom', '2030')->assertSee('No survey responses match this view yet');
        $this->assertSame([], $rows());

        $page->call('clearFilters')->assertSet('yearFrom', '');
        $this->assertSame(['School of Arts', 'School of Science'], $rows());
    }

    public function test_the_comparison_can_be_changed(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 5], ['graduation_year' => 2023]);
        $this->cohort($this->programmeIn('School of Science', 'BSc Chemistry'), ['employed' => 5], ['graduation_year' => 2024]);

        Livewire::actingAs($this->staff())->test(Outcomes::class)
            ->set('groupBy', 'programme')->assertSee('Employment outcome by programme')->assertSee('BSc Biology')->assertSee('BSc Chemistry')
            ->set('groupBy', 'year')->assertSee('Employment outcome by graduation year')->assertSee('2023')->assertSee('2024');
    }

    public function test_the_state_lives_in_the_url_so_a_view_can_be_bookmarked_and_shared(): void
    {
        $biology = $this->programmeIn('School of Science', 'BSc Biology');
        foreach (range(1, 6) as $i) {
            $this->graduate(['programme_id' => $biology->id, 'employment_status' => 'employed', 'graduation_year' => 2024, 'student_number' => 'SU/'.Str::random(8)]);
        }

        $this->actingAs($this->staff())->get('/admin/outcomes?source=profiles&groupBy=year&yearFrom=2024')
            ->assertOk()
            ->assertSee('Employment outcome by graduation year')
            ->assertSee('Graduates with a known status');
    }

    // ---- export ----------------------------------------------------------------------------------------------------

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function parseCsv(string $csv): array
    {
        $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));

        return [array_shift($rows), array_values($rows)];
    }

    public function test_the_export_matches_the_screen(): void
    {
        $this->cohort($this->programmeIn('School of Science', 'BSc Biology'), ['employed' => 5, 'self_employed' => 2, 'unemployed' => 2, 'further_study' => 1]);

        [$headings, $rows] = $this->parseCsv($this->actingAs($this->staff('qaViewer'))->get(route('admin.outcomes.export'))->streamedContent());

        $this->assertSame('School', $headings[0]);
        $this->assertContains('Employed %', $headings);
        $this->assertCount(count($headings), $rows[0], 'every row has every column');

        $summary = array_combine($headings, $rows[0]);
        $this->assertSame('All graduates in this view', $summary['School']);
        $this->assertSame('10', $summary['Graduates who answered']);
        $this->assertSame('70', $summary['In work %']);
        $this->assertSame('5', $summary['Employed (count)']);
        $this->assertSame('50', $summary['Employed %']);

        $school = array_combine($headings, $rows[1]);
        $this->assertSame('School of Science', $school['School']);
        $this->assertSame('20', $school['Self-employed %']);
    }

    public function test_the_export_withholds_small_groups_exactly_as_the_screen_does(): void
    {
        $this->cohort($this->programmeIn('Big School', 'Big'), ['employed' => 6]);
        $this->cohort($this->programmeIn('Tiny School', 'Tiny'), ['unemployed' => 3]);

        [$headings, $rows] = $this->parseCsv($this->actingAs($this->staff())->get(route('admin.outcomes.export'))->streamedContent());

        $tiny = array_combine($headings, collect($rows)->first(fn ($r) => $r[0] === 'Tiny School'));
        $this->assertSame('fewer than 5', $tiny['Graduates who answered']);
        $this->assertSame('', $tiny['Seeking work (count)']);
        $this->assertSame('', $tiny['Seeking work %']);
        $this->assertSame('', $tiny['In work %']);
        $this->assertCount(count($headings), collect($rows)->first(fn ($r) => $r[0] === 'Tiny School'));
    }

    public function test_the_export_honours_the_filters_and_the_chosen_grouping(): void
    {
        $bio = $this->programmeIn('School of Science', 'BSc Biology');
        $this->cohort($bio, ['employed' => 5], ['graduation_year' => 2023]);
        $this->cohort($bio, ['unemployed' => 5], ['graduation_year' => 2024]);

        [$headings, $rows] = $this->parseCsv(
            $this->actingAs($this->staff())->get(route('admin.outcomes.export', ['groupBy' => 'year', 'yearFrom' => 2024]))->streamedContent()
        );

        $this->assertSame('Graduation year', $headings[0]);
        $this->assertSame(['All graduates in this view', '2024'], array_column($rows, 0));
        $this->assertSame('5', array_combine($headings, $rows[0])['Graduates who answered'], 'only the 2024 cohort (5 people) is counted');
    }

    public function test_the_export_neutralises_spreadsheet_formulas_in_group_names(): void
    {
        $this->cohort($this->programmeIn('=HYPERLINK("http://evil.example","x")', 'Programme'), ['employed' => 5]);

        $csv = $this->actingAs($this->staff())->get(route('admin.outcomes.export'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_the_export_with_no_data_is_still_a_valid_file(): void
    {
        [$headings, $rows] = $this->parseCsv($this->actingAs($this->staff())->get(route('admin.outcomes.export'))->streamedContent());

        $this->assertNotEmpty($headings);
        $this->assertCount(1, $rows, 'just the (withheld) overall row');
    }

    public function test_the_export_ignores_hostile_query_strings(): void
    {
        $this->actingAs($this->staff())
            ->get(route('admin.outcomes.export', ['groupBy' => "x'; DROP TABLE alumni_profiles;--", 'schoolId' => 'abc', 'source' => '../../etc/passwd']))
            ->assertOk();

        $this->assertSame(0, AlumniProfile::count());
    }
}

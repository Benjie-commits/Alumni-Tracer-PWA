<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AlumniDirectory;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use Livewire\Livewire;

class DirectoryTest extends AdminTestCase
{
    private function programmeIn(School $school, string $name): Programme
    {
        $department = Department::factory()->create(['school_id' => $school->id]);

        return Programme::factory()->create(['department_id' => $department->id, 'name' => $name]);
    }

    public function test_search_finds_by_name_and_student_number(): void
    {
        $this->profile(['first_name' => 'Amina', 'last_name' => 'Okello', 'student_number' => 'SU/2021/001']);
        $this->profile(['first_name' => 'Peter', 'last_name' => 'Ojok', 'student_number' => 'SU/2021/002']);

        Livewire::actingAs($this->registrar())->test(AlumniDirectory::class)
            ->set('search', 'okello')->assertSee('Okello')->assertDontSee('Ojok')
            ->set('search', 'SU/2021/002')->assertSee('Ojok')->assertDontSee('Okello');
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->profile(['first_name' => 'Amina', 'last_name' => 'Okello']);

        Livewire::actingAs($this->registrar())->test(AlumniDirectory::class)
            ->set('search', '%')->assertDontSee('Okello');
    }

    public function test_filters_narrow_by_school_department_programme_and_year(): void
    {
        $science = School::factory()->create(['name' => 'School of Science']);
        $arts = School::factory()->create(['name' => 'School of Arts']);
        $biology = $this->programmeIn($science, 'BSc Biology');
        $history = $this->programmeIn($arts, 'BA History');
        $this->profile(['last_name' => 'Bioperson', 'programme_id' => $biology->id, 'graduation_year' => 2023]);
        $this->profile(['last_name' => 'Bioperson2', 'programme_id' => $biology->id, 'graduation_year' => 2024]);
        $this->profile(['last_name' => 'Historian', 'programme_id' => $history->id, 'graduation_year' => 2023]);

        $page = Livewire::actingAs($this->registrar())->test(AlumniDirectory::class);

        $page->set('schoolId', (string) $science->id)->assertSee('Bioperson')->assertDontSee('Historian');
        $page->set('year', '2023')->assertSee('Bioperson')->assertDontSee('Bioperson2')->assertDontSee('Historian');
        $page->call('clearFilters')->assertSee('Historian');
        $page->set('schoolId', (string) $arts->id)->set('departmentId', (string) $history->department_id)
            ->assertSee('Historian')->assertDontSee('Bioperson');
        $page->call('clearFilters')->set('programmeId', (string) $biology->id)
            ->assertSee('Bioperson')->assertDontSee('Historian');
    }

    public function test_changing_school_resets_dependent_filters(): void
    {
        $school = School::factory()->create();
        $programme = $this->programmeIn($school, 'BSc Biology');

        Livewire::actingAs($this->registrar())->test(AlumniDirectory::class)
            ->set('schoolId', (string) $school->id)
            ->set('departmentId', (string) $programme->department_id)
            ->set('programmeId', (string) $programme->id)
            ->set('schoolId', '')
            ->assertSet('departmentId', '')
            ->assertSet('programmeId', '');
    }

    public function test_registrars_see_contact_details_and_can_search_by_them(): void
    {
        $this->profile(['last_name' => 'Okello', 'email' => 'okello.private@example.com', 'phone' => '+256700555111']);

        Livewire::actingAs($this->registrar())->test(AlumniDirectory::class)
            ->assertSee('okello.private@example.com')
            ->set('search', 'okello.private')->assertSee('Okello');
    }

    public function test_qa_viewers_never_receive_contact_details_and_cannot_probe_for_them(): void
    {
        $this->profile(['last_name' => 'Okello', 'email' => 'okello.private@example.com', 'phone' => '+256700555111']);

        Livewire::actingAs($this->qaViewer())->test(AlumniDirectory::class)
            ->assertSee('Okello')
            ->assertDontSee('okello.private@example.com')
            ->assertDontSee('+256700555111')
            ->set('search', 'okello.private')->assertDontSee('Okello')
            ->set('search', '256700555111')->assertDontSee('Okello');
    }

    public function test_export_respects_filters_and_includes_contact_for_registrars(): void
    {
        $this->profile(['last_name' => 'Okello', 'graduation_year' => 2023, 'email' => 'okello@example.com', 'phone' => '+256700555111']);
        $this->profile(['last_name' => 'Ojok', 'graduation_year' => 2024, 'email' => 'ojok@example.com']);

        $response = $this->actingAs($this->registrar())->get(route('admin.alumni.export', ['year' => 2023]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Okello', $csv);
        $this->assertStringNotContainsString('Ojok', $csv);
        $this->assertStringContainsString('Email,Phone,WhatsApp', $csv);
        $this->assertStringContainsString('okello@example.com', $csv);
        $this->assertStringContainsString('+256700555111', $csv, 'real phone numbers must not be mangled');
    }

    public function test_export_omits_contact_columns_for_qa_viewers(): void
    {
        $this->profile(['last_name' => 'Okello', 'email' => 'okello@example.com', 'phone' => '+256700555111']);

        $csv = $this->actingAs($this->qaViewer())->get(route('admin.alumni.export'))->streamedContent();

        $this->assertStringContainsString('Okello', $csv);
        $this->assertStringNotContainsString('Email', $csv);
        $this->assertStringNotContainsString('okello@example.com', $csv);
        $this->assertStringNotContainsString('+256700555111', $csv);
    }

    public function test_export_neutralises_spreadsheet_formulas_typed_by_alumni(): void
    {
        $this->profile(['first_name' => '=HYPERLINK("http://evil.example","click")', 'last_name' => '@SUM(1+1)', 'city' => '-2+3']);

        $csv = $this->actingAs($this->registrar())->get(route('admin.alumni.export'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM", $csv);
        $this->assertStringContainsString("'-2+3", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_export_requires_staff(): void
    {
        $this->get(route('admin.alumni.export'))->assertRedirect(route('admin.login'));
    }
}

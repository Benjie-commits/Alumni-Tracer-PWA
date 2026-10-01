<?php

namespace Tests\Unit;

use App\Services\Erp\GraduateMapper;
use Tests\TestCase;

class GraduateMapperTest extends TestCase
{
    /** An ERP that names things its own way. */
    private function mapper(array $fields = [], array $graduated = ['graduated']): GraduateMapper
    {
        return new GraduateMapper($fields + [
            'student_number' => 'regNo',
            'first_name' => 'givenName',
            'last_name' => 'familyName',
            'programme' => 'course.name',
            'school' => 'course.school',
            'graduation_date' => 'conferredOn',
            'graduation_year' => 'year',
            'gender' => 'sex',
            'status' => null,
            'updated_at' => null,
        ], $graduated);
    }

    private function record(array $overrides = []): array
    {
        return $overrides + [
            'regNo' => ' su/2026/007 ',
            'givenName' => 'Peter',
            'familyName' => 'Ojok',
            'course' => ['name' => 'BSc Biology', 'school' => 'School of Science'],
            'conferredOn' => '2026-07-15T00:00:00Z',
            'year' => 2026,
            'sex' => 'M',
            'internalNote' => 'never copied',
        ];
    }

    public function test_it_translates_the_erp_s_names_into_ours_and_trims_values(): void
    {
        $row = $this->mapper()->map($this->record());

        $this->assertSame('su/2026/007', $row['student_number']);
        $this->assertSame('Peter', $row['first_name']);
        $this->assertSame('Ojok', $row['last_name']);
        $this->assertSame('BSc Biology', $row['programme'], 'dot notation reaches nested JSON');
        $this->assertSame('School of Science', $row['school']);
        $this->assertSame('M', $row['gender']);
        $this->assertSame('2026', $row['graduation_year'], 'numbers become text for the import rules');
    }

    public function test_only_mapped_fields_are_ever_copied(): void
    {
        $row = $this->mapper()->map($this->record());

        $this->assertNotContains('never copied', $row);
        $this->assertSame(GraduateMapper::COLUMNS, array_keys($row));
    }

    public function test_fields_the_erp_does_not_provide_come_through_empty(): void
    {
        $row = $this->mapper()->map($this->record());

        $this->assertSame('', $row['email']);
        $this->assertSame('', $row['class_of_award']);
        $this->assertSame('', $row['other_names']);
    }

    public function test_erp_timestamps_are_cut_down_to_the_date_the_import_rules_expect(): void
    {
        $row = $this->mapper()->map($this->record(['conferredOn' => '2026-07-15 00:00:00']));
        $this->assertSame('2026-07-15', $row['graduation_date']);

        // Anything else is left for the import rules to accept or reject (dd/mm/yyyy is valid there).
        $row = $this->mapper()->map($this->record(['conferredOn' => '15/07/2026']));
        $this->assertSame('15/07/2026', $row['graduation_date']);
    }

    public function test_non_text_values_are_treated_as_empty_not_cast_into_nonsense(): void
    {
        $row = $this->mapper()->map($this->record(['givenName' => ['nested'], 'familyName' => true, 'sex' => null]));

        $this->assertSame('', $row['first_name']);
        $this->assertSame('', $row['last_name']);
        $this->assertSame('', $row['gender']);
    }

    public function test_without_a_status_field_every_record_is_taken_to_be_a_graduate(): void
    {
        $this->assertNotNull($this->mapper()->map($this->record(['state' => 'ENROLLED'])));
    }

    public function test_with_a_status_field_only_graduated_values_pass_whatever_their_case(): void
    {
        $mapper = $this->mapper(['status' => 'state'], ['graduated', 'alumnus']);

        $this->assertNotNull($mapper->map($this->record(['state' => 'GRADUATED'])));
        $this->assertNotNull($mapper->map($this->record(['state' => ' Alumnus '])));
        $this->assertNull($mapper->map($this->record(['state' => 'Enrolled'])), 'still studying: not an alumnus yet');
        $this->assertNull($mapper->map($this->record()), 'no status at all is not "graduated"');
    }

    public function test_it_names_the_required_fields_a_record_has_nothing_for(): void
    {
        $this->assertSame([], $this->mapper()->missingRequired($this->record()));
        $this->assertSame(['student_number', 'last_name'], $this->mapper()->missingRequired($this->record(['regNo' => '', 'familyName' => null])));
    }

    public function test_it_reads_when_a_record_last_changed(): void
    {
        $mapper = $this->mapper(['updated_at' => 'modified']);

        $this->assertSame('2026-09-30 12:00', $mapper->changedAt($this->record(['modified' => '2026-09-30T12:00:00+03:00']))->timezone('Africa/Kampala')->format('Y-m-d H:i'));
        $this->assertNull($mapper->changedAt($this->record(['modified' => 'not a date'])));
        $this->assertNull($mapper->changedAt($this->record()));
        $this->assertNull($this->mapper()->changedAt($this->record(['modified' => '2026-09-30'])), 'no mapped field, no timestamp');
    }

    public function test_it_lists_exactly_the_erp_fields_it_reads_so_a_query_can_select_only_those(): void
    {
        $mapper = $this->mapper(['status' => 'state', 'updated_at' => 'modified']);

        $this->assertEqualsCanonicalizing(
            ['regNo', 'givenName', 'familyName', 'course.name', 'course.school', 'conferredOn', 'year', 'sex', 'state', 'modified'],
            $mapper->sourceFields(),
        );
    }

    public function test_blank_mappings_count_as_not_provided(): void
    {
        $mapper = $this->mapper(['email' => '  ', 'phone' => null]);

        $this->assertNull($mapper->field('email'));
        $this->assertNull($mapper->field('phone'));
        $this->assertSame('regNo', $mapper->field('student_number'));
    }
}

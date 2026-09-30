<?php

namespace Tests\Feature;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Programme;
use App\Models\School;
use App\Services\Import\AlumniImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AlumniImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'Reg No,First Name,Surname,Sex,School,Department,Course,Year of Graduation,Graduation Date,Email,Phone';

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string ...$lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sunates');
        file_put_contents($path, implode("\n", $lines)."\n");

        return $this->files[] = $path;
    }

    private function import(string $path, bool $dryRun = false)
    {
        return app(AlumniImportService::class)->importFile($path, $dryRun);
    }

    public function test_it_creates_unclaimed_records_and_builds_the_school_hierarchy(): void
    {
        $report = $this->import($this->csv(
            self::HEADER,
            'SU/2021/001,Amina,Okello,F,School of Science,Department of Biology,BSc Biology,2024,15/07/2024,amina@example.com,+256700111222',
            'SU/2021/002,Peter,Ojok,M,School of Science,Department of Biology,BSc Biology,2024,,,',
        ));

        $this->assertSame(2, $report->rows);
        $this->assertSame(2, $report->created);
        $this->assertSame(0, $report->skipped);
        $this->assertSame(3, $report->referenceCreated, 'one school, one department, one programme');

        $amina = AlumniProfile::where('student_number', 'SU/2021/001')->first();
        $this->assertNull($amina->user_id);
        $this->assertSame(VerificationStatus::Unclaimed, $amina->verification_status);
        $this->assertSame('female', $amina->gender);
        $this->assertSame(2024, $amina->graduation_year);
        $this->assertSame('2024-07-15', $amina->graduation_date->toDateString());
        $this->assertSame('BSc Biology', $amina->programme->name);
        $this->assertSame('School of Science', $amina->programme->department->school->name);
        $this->assertSame(1, School::count());
    }

    public function test_a_dry_run_reports_the_same_numbers_but_writes_nothing(): void
    {
        $lines = [self::HEADER, 'SU/2021/001,Amina,Okello,F,School of Science,Dept,BSc Biology,2024,,,'];

        $report = $this->import($this->csv(...$lines), dryRun: true);

        $this->assertTrue($report->dryRun);
        $this->assertSame(1, $report->created);
        $this->assertSame(0, AlumniProfile::count());
        $this->assertSame(0, School::count());
        $this->assertSame(0, Programme::count());
    }

    public function test_reimporting_the_same_sheet_changes_nothing(): void
    {
        $path = $this->csv(self::HEADER, 'SU/2021/001,Amina,Okello,F,School of Science,Dept,BSc Biology,2024,,,');

        $this->import($path);
        $second = $this->import($path);

        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, $second->unchanged);
        $this->assertSame(1, AlumniProfile::count());
    }

    public function test_registrar_fields_are_refreshed_but_alumnus_contact_details_are_kept(): void
    {
        AlumniProfile::factory()->create([
            'student_number' => 'SU/2021/001',
            'first_name' => 'Amina',
            'last_name' => 'Okelo',
            'email' => 'own.choice@example.com',
            'phone' => '+256799000000',
        ]);

        $report = $this->import($this->csv(
            self::HEADER,
            'SU/2021/001,Amina,Okello,F,School of Science,Dept,BSc Biology,2024,,sheet@example.com,+256700111222',
        ));

        $this->assertSame(1, $report->updated);
        $profile = AlumniProfile::first();
        $this->assertSame('Okello', $profile->last_name, 'Registrar-owned field is corrected from the sheet');
        $this->assertSame('own.choice@example.com', $profile->email, 'alumnus contact details are not overwritten');
        $this->assertSame('+256799000000', $profile->phone);
    }

    public function test_a_blank_cell_does_not_erase_existing_registrar_data(): void
    {
        AlumniProfile::factory()->create(['student_number' => 'SU/2021/001', 'graduation_year' => 2023, 'class_of_award' => 'Second Class Upper']);

        $this->import($this->csv(self::HEADER, 'SU/2021/001,Amina,Okello,,,,,,,,'));

        $profile = AlumniProfile::first();
        $this->assertSame(2023, $profile->graduation_year);
        $this->assertSame('Second Class Upper', $profile->class_of_award);
    }

    public function test_bad_rows_are_skipped_with_a_reason_and_good_rows_still_import(): void
    {
        $report = $this->import($this->csv(
            self::HEADER,
            'SU/2021/001,Amina,Okello,F,,,,2024,,,',
            ',No,Number,F,,,,2024,,,',
            'SU/2021/003,Bad,Year,F,,,,1850,,,',
            'SU/2021/004,Bad,Date,F,,,,,31/02/2024,,',
            'SU/2021/005,Peter,Ojok,M,,,,2023,,,',
        ));

        $this->assertSame(5, $report->rows);
        $this->assertSame(2, $report->created);
        $this->assertSame(3, $report->skipped);
        $this->assertSame([3, 4, 5], array_column($report->errors, 'row'));
        $this->assertStringContainsString('student number', $report->errors[0]['message']);
        $this->assertStringContainsString('graduation year', $report->errors[1]['message']);
        $this->assertStringContainsString('graduation date', $report->errors[2]['message']);
    }

    public function test_a_programme_with_no_school_is_filed_under_unassigned_with_a_warning(): void
    {
        $report = $this->import($this->csv(self::HEADER, 'SU/2021/001,Amina,Okello,F,,,BSc Biology,2024,,,'));

        $this->assertSame(1, $report->created);
        $this->assertCount(1, $report->warnings);
        $this->assertSame('Unassigned', AlumniProfile::first()->programme->department->school->name);
    }

    public function test_duplicate_student_numbers_in_one_file_warn(): void
    {
        $report = $this->import($this->csv(
            self::HEADER,
            'SU/2021/001,Amina,Okello,F,,,,2024,,,',
            'su/2021/001,Amina,Okello-Achieng,F,,,,2024,,,',
        ));

        $this->assertSame(1, $report->created);
        $this->assertSame(1, $report->updated);
        $this->assertCount(1, $report->warnings);
        $this->assertSame('Okello-Achieng', AlumniProfile::first()->last_name);
    }

    public function test_headings_are_matched_loosely_and_semicolon_files_work(): void
    {
        $report = $this->import($this->csv(
            "\u{FEFF}STUDENT NO;given names;SURNAME;year",
            'SU/2021/001;Amina;Okello;2024',
        ));

        $this->assertSame(1, $report->created);
        $this->assertSame(2024, AlumniProfile::first()->graduation_year);
    }

    public function test_a_file_missing_required_columns_is_rejected_outright(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('student number');

        $this->import($this->csv('First Name,Surname', 'Amina,Okello'));
    }

    public function test_windows_1252_encoded_names_survive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sunates');
        $this->files[] = $path;
        file_put_contents($path, mb_convert_encoding("Reg No,First Name,Surname\nSU/2021/009,Zo\u{E9},Ochieng\n", 'Windows-1252', 'UTF-8'));

        $this->import($path);

        $this->assertSame("Zo\u{E9}", AlumniProfile::first()->first_name);
    }

    public function test_soft_deleted_records_are_not_silently_resurrected_or_duplicated(): void
    {
        AlumniProfile::factory()->create(['student_number' => 'SU/2021/001'])->delete();

        $report = $this->import($this->csv(self::HEADER, 'SU/2021/001,Amina,Okello,F,,,,2024,,,'));

        $this->assertSame(1, $report->skipped);
        $this->assertStringContainsString('deleted', $report->errors[0]['message']);
        $this->assertSame(0, AlumniProfile::count());
    }
}

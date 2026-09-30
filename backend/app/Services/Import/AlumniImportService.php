<?php

namespace App\Services\Import;

use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FR-8: seed and refresh the alumni directory from the Registrar's existing spreadsheets.
 *
 * Rows are matched on student number. Registrar-owned fields (names, programme, graduation
 * details) are always refreshed from the sheet; contact details are only filled in where the
 * record has none, so an alumnus's own edits are never overwritten by an old spreadsheet.
 */
class AlumniImportService
{
    private const UNASSIGNED = 'Unassigned';

    /** @var array<string, int> */
    private array $seenStudentNumbers = [];

    public function __construct(private readonly CsvReader $reader) {}

    /**
     * A dry run does all the work inside a transaction and rolls it back, so the report is exactly
     * what a real import would produce.
     */
    public function importFile(string $path, bool $dryRun = false): ImportReport
    {
        $report = new ImportReport($dryRun);
        $this->seenStudentNumbers = [];

        DB::beginTransaction();

        try {
            foreach ($this->reader->read($path) as $rowNumber => $row) {
                $report->rows++;

                try {
                    // Savepoint per row so one bad row cannot poison the surrounding transaction.
                    DB::transaction(fn () => $this->importRow($rowNumber, $row, $report));
                } catch (RowRejected $e) {
                    $report->error($rowNumber, $e->getMessage());
                } catch (Throwable $e) {
                    report($e);
                    $report->error($rowNumber, 'Unexpected error while saving this row.');
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(int $rowNumber, array $row, ImportReport $report): void
    {
        $studentNumber = strtoupper((string) preg_replace('/\s+/u', ' ', $row['student_number'] ?? ''));
        $firstName = $this->clean($row['first_name'] ?? '');
        $lastName = $this->clean($row['last_name'] ?? '');

        foreach (['student number' => $studentNumber, 'first name' => $firstName, 'last name' => $lastName] as $label => $value) {
            if ($value === '') {
                throw new RowRejected("Missing {$label}.");
            }
        }

        $graduationDate = $this->date($row['graduation_date'] ?? '', 'graduation date');
        $dateOfBirth = $this->date($row['date_of_birth'] ?? '', 'date of birth');
        $year = $this->year($row['graduation_year'] ?? '') ?? $graduationDate?->year;

        // Look the record up before touching reference data, so a rejected row leaves no trace.
        $profile = AlumniProfile::withTrashed()->where('student_number', $studentNumber)->first();
        if ($profile?->trashed()) {
            throw new RowRejected("Student number {$studentNumber} belongs to a deleted record; restore it before importing.");
        }

        if (isset($this->seenStudentNumbers[$studentNumber])) {
            $report->warn($rowNumber, "Student number {$studentNumber} already appeared on row {$this->seenStudentNumbers[$studentNumber]}; this row updates it.");
        }
        $this->seenStudentNumbers[$studentNumber] = $rowNumber;

        $registrarOwned = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'other_names' => $this->clean($row['other_names'] ?? '') ?: null,
            'gender' => $this->gender($row['gender'] ?? ''),
            'date_of_birth' => $dateOfBirth?->toDateString(),
            'graduation_year' => $year,
            'graduation_date' => $graduationDate?->toDateString(),
            'class_of_award' => $this->clean($row['class_of_award'] ?? '') ?: null,
            'programme_id' => $this->programmeId($rowNumber, $row, $report),
        ];
        // A blank cell in the sheet must not erase what the Registrar already holds.
        $registrarOwned = array_filter($registrarOwned, fn ($v) => $v !== null);

        $email = $this->clean($row['email'] ?? '');
        $phone = $this->clean($row['phone'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $report->warn($rowNumber, "Ignored invalid email address '{$email}'.");
            $email = '';
        }

        if ($profile === null) {
            AlumniProfile::query()->create($registrarOwned + [
                'student_number' => $studentNumber,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'record_source' => RecordSource::RegistrarImport,
                'verification_status' => VerificationStatus::Unclaimed,
            ]);
            $report->created++;

            return;
        }

        $profile->fill($registrarOwned);
        if ($email !== '' && $profile->email === null) {
            $profile->email = $email;
        }
        if ($phone !== '' && $profile->phone === null) {
            $profile->phone = $phone;
        }

        if ($profile->isDirty()) {
            $profile->save();
            $report->updated++;
        } else {
            $report->unchanged++;
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function programmeId(int $rowNumber, array $row, ImportReport $report): ?int
    {
        $programmeName = $this->clean($row['programme'] ?? '');
        if ($programmeName === '') {
            return null;
        }

        $schoolName = $this->clean($row['school'] ?? '');
        $departmentName = $this->clean($row['department'] ?? '');

        if ($schoolName === '') {
            $report->warn($rowNumber, "Programme '{$programmeName}' has no school; filed under '".self::UNASSIGNED."'.");
            $schoolName = self::UNASSIGNED;
        }
        if ($departmentName === '') {
            $departmentName = self::UNASSIGNED;
        }

        $school = School::query()->firstOrCreate(['name' => $schoolName]);
        $department = Department::query()->firstOrCreate(['school_id' => $school->id, 'name' => $departmentName]);
        $programme = Programme::query()->firstOrCreate(['department_id' => $department->id, 'name' => $programmeName]);

        foreach ([$school, $department, $programme] as $model) {
            if ($model->wasRecentlyCreated) {
                $report->referenceCreated++;
            }
        }

        return $programme->id;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function gender(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'm', 'male' => 'male',
            'f', 'female' => 'female',
            '' => null,
            default => 'other',
        };
    }

    private function year(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^\d{4}$/', $value) || (int) $value < 1950 || (int) $value > (int) date('Y') + 1) {
            throw new RowRejected("Invalid graduation year '{$value}'.");
        }

        return (int) $value;
    }

    private function date(string $value, string $label): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Day-first is the norm for Ugandan spreadsheets; ISO dates are accepted too.
        // Native parsing returns false on failure (Carbon 3 throws), and the round-trip check
        // rejects overflow such as 31/02/2024 that PHP would silently roll into March.
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'j/n/Y', 'j-n-Y', 'd M Y', 'j F Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return Carbon::instance($date);
            }
        }

        throw new RowRejected("Unrecognised {$label} '{$value}' (use dd/mm/yyyy or yyyy-mm-dd).");
    }
}

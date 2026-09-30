<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AlumniProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FR-1: export the directory with whatever filters are active. Contact details are exported only
 * for staff who may manage records; QA/Dean viewers get outcome and academic data.
 */
class AlumniExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'schoolId' => ['nullable', 'integer'],
            'departmentId' => ['nullable', 'integer'],
            'programmeId' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(VerificationStatus::class)],
        ]);

        $withContact = $request->user()->canManageRecords();

        $query = AlumniProfile::query()
            ->with('programme.department.school')
            ->search($filters['q'] ?? null, $withContact)
            ->filter([
                'school_id' => $filters['schoolId'] ?? null,
                'department_id' => $filters['departmentId'] ?? null,
                'programme_id' => $filters['programmeId'] ?? null,
                'graduation_year' => $filters['year'] ?? null,
                'verification_status' => $filters['status'] ?? null,
            ])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id');

        $headings = ['Student number', 'First name', 'Other names', 'Last name', 'Gender', 'School', 'Department', 'Programme',
            'Graduation year', 'Class of award', 'Employment status', 'Further study', 'City', 'Country', 'Record status', 'Profile last updated'];
        if ($withContact) {
            array_push($headings, 'Email', 'Phone', 'WhatsApp');
        }

        $filename = 'sunates-alumni-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $headings, $withContact) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names as UTF-8
            fputcsv($out, $headings, ',', '"', '');

            $query->chunk(500, function ($profiles) use ($out, $withContact) {
                foreach ($profiles as $p) {
                    $row = [
                        $p->student_number, $p->first_name, $p->other_names, $p->last_name, $p->gender,
                        $p->programme?->department?->school?->name, $p->programme?->department?->name, $p->programme?->name,
                        $p->graduation_year, $p->class_of_award, $p->employment_status?->label(), $p->further_study_status?->label(),
                        $p->city, $p->country, $p->verification_status->label(), $p->profile_updated_at?->toDateString(),
                    ];
                    if ($withContact) {
                        array_push($row, $p->email, $p->phone, $p->whatsapp_number);
                    }

                    fputcsv($out, array_map($this->safe(...), $row), ',', '"', '');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Alumni type their own names and employers, so a cell like =HYPERLINK(...) must not run when
     * staff open the export in Excel. Real phone numbers are left alone.
     */
    private function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^\+?[0-9][0-9\s\-]*$/', $value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}

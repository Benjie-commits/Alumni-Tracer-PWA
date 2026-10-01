<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AlumniProfile;
use App\Support\Csv;
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
            'ta' => ['nullable', 'boolean'],
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
                'ta_candidate' => $filters['ta'] ?? null,
            ])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id');

        $headings = ['Student number', 'First name', 'Other names', 'Last name', 'Gender', 'School', 'Department', 'Programme',
            'Graduation year', 'Class of award', 'Employment status', 'Further study', 'City', 'Country', 'Record status',
            'Profile last updated', 'Teaching-assistant candidate'];
        if ($withContact) {
            array_push($headings, 'Email', 'Phone', 'WhatsApp');
        }

        $rows = function () use ($query, $withContact) {
            foreach ($query->lazy(500) as $p) {
                $row = [
                    $p->student_number, $p->first_name, $p->other_names, $p->last_name, $p->gender,
                    $p->programme?->department?->school?->name, $p->programme?->department?->name, $p->programme?->name,
                    $p->graduation_year, $p->class_of_award, $p->employment_status?->label(), $p->further_study_status?->label(),
                    $p->city, $p->country, $p->verification_status->label(), $p->profile_updated_at?->toDateString(),
                    $p->ta_flagged_at ? 'Yes' : '',
                ];
                if ($withContact) {
                    array_push($row, $p->email, $p->phone, $p->whatsapp_number);
                }

                yield $row;
            }
        };

        return response()->streamDownload(
            fn () => Csv::stream($headings, $rows()),
            'sunates-alumni-'.now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}

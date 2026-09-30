<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Enums\FurtherStudyStatus;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;

/**
 * Public lookups the PWA needs before sign-in (the registration form) and for its selects.
 */
class ReferenceDataController extends Controller
{
    public function programmes(): JsonResponse
    {
        $schools = School::query()
            ->with(['departments' => fn ($q) => $q->orderBy('name'), 'departments.programmes' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $schools->map(fn (School $school) => [
                'id' => $school->id,
                'name' => $school->name,
                'departments' => $school->departments->map(fn ($department) => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'programmes' => $department->programmes->map(fn ($programme) => [
                        'id' => $programme->id,
                        'name' => $programme->name,
                    ])->values(),
                ])->values(),
            ])->values(),
        ]);
    }

    public function options(): JsonResponse
    {
        $options = fn (string $enum) => collect($enum::cases())
            ->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])
            ->values();

        return response()->json(['data' => [
            'employment_status' => $options(EmploymentStatus::class),
            'employment_type' => $options(EmploymentType::class),
            'further_study_status' => $options(FurtherStudyStatus::class),
        ]]);
    }
}

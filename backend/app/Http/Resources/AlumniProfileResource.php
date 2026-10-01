<?php

namespace App\Http\Resources;

use App\Models\AlumniProfile;
use App\Models\Programme;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The alumnus's own view of their profile (never used for other people's records).
 *
 * @mixin AlumniProfile
 */
class AlumniProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $programme = $this->whenLoaded('programme');

        return [
            'id' => $this->id,
            'student_number' => $this->student_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'other_names' => $this->other_names,
            'full_name' => $this->full_name,
            'programme' => $programme instanceof Programme ? [
                'id' => $programme->id,
                'name' => $programme->name,
                'department' => $programme->department?->name,
                'school' => $programme->department?->school?->name,
            ] : null,
            'graduation_year' => $this->graduation_year,
            'graduation_date' => $this->graduation_date?->toDateString(),
            'class_of_award' => $this->class_of_award,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp_number' => $this->whatsapp_number,
            'country' => $this->country,
            'city' => $this->city,
            'linkedin_url' => $this->linkedin_url,
            'employment_status' => $this->employment_status?->value,
            'further_study_status' => $this->further_study_status?->value,
            'further_study_institution' => $this->further_study_institution,
            'further_study_programme' => $this->further_study_programme,
            'verification_status' => [
                'value' => $this->verification_status->value,
                'label' => $this->verification_status->label(),
            ],
            'profile_updated_at' => $this->profile_updated_at?->toIso8601String(),
            'employment_records' => EmploymentRecordResource::collection($this->whenLoaded('employmentRecords')),
        ];
    }
}

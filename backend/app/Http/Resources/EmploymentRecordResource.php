<?php

namespace App\Http\Resources;

use App\Models\EmploymentRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmploymentRecord
 */
class EmploymentRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employer' => $this->employer,
            'job_title' => $this->job_title,
            'sector' => $this->sector,
            'employment_type' => $this->employment_type?->value,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_current' => $this->is_current,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }
}

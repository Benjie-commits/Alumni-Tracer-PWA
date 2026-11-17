<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role?->slug,
            'profile' => new AlumniProfileResource($this->whenLoaded('alumniProfile')),
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'student_number' => $this->student->student_number,
                'first_name' => $this->student->first_name,
                'last_name' => $this->student->last_name,
                'programme' => $this->student->programme?->name,
                'year_of_study' => $this->student->year_of_study,
            ] : null),
        ];
    }
}

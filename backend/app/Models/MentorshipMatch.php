<?php

namespace App\Models;

use App\Enums\MentorshipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An alumnus mentoring a current student, matched by staff (FR-5). */
#[Fillable(['alumni_profile_id', 'student_id', 'status', 'focus', 'started_at', 'ended_at', 'created_by', 'ended_by'])]
class MentorshipMatch extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MentorshipStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

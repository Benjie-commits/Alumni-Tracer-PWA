<?php

namespace App\Models;

use App\Enums\MentorshipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A continuing student (FR-5). Recorded by staff, or self-registered from the app; with an account
 * (user_id) the student sees events and internships and can ask for a mentor.
 */
#[Fillable([
    'student_number', 'first_name', 'last_name', 'programme_id', 'year_of_study', 'email', 'phone',
    'contact_shared_at', 'created_by', 'user_id', 'mentor_requested_at', 'mentor_request_note',
])]
class Student extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['contact_shared_at' => 'datetime', 'mentor_requested_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function mentorships(): HasMany
    {
        return $this->hasMany(MentorshipMatch::class);
    }

    public function activeMentorship(): ?MentorshipMatch
    {
        return $this->mentorships->firstWhere('status', MentorshipStatus::Active);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}

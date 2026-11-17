<?php

namespace App\Models\Concerns;

use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-5: a posting aimed at an alumni segment. Nothing set = every alumnus; otherwise a school, department
 * or programme, and/or a range of graduation years. An alumnus sees the posting only if they fit every
 * part that is set. Someone whose programme or year is unknown sees only postings that do not need it.
 */
trait TargetsAlumni
{
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function scopeVisibleTo(Builder $query, AlumniProfile $profile): Builder
    {
        $profile->loadMissing('programme.department');

        return $this->scopeVisibleToSegment($query, $profile->programme, $profile->graduation_year);
    }

    /**
     * Continuing students: matched on their programme / department / school. They have no graduation
     * year yet, so postings aimed at particular classes of graduates are not shown to them.
     */
    public function scopeVisibleToStudent(Builder $query, Student $student): Builder
    {
        $student->loadMissing('programme.department');

        return $this->scopeVisibleToSegment($query, $student->programme, null);
    }

    public function scopeVisibleToSegment(Builder $query, ?Programme $programme, ?int $year): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('school_id')->orWhere('school_id', $programme?->department?->school_id ?? 0))
            ->where(fn (Builder $q) => $q->whereNull('department_id')->orWhere('department_id', $programme?->department_id ?? 0))
            ->where(fn (Builder $q) => $q->whereNull('programme_id')->orWhere('programme_id', $programme?->id ?? 0))
            ->where(fn (Builder $q) => $q->whereNull('graduation_year_from')->orWhere('graduation_year_from', '<=', $year ?? 0))
            ->where(fn (Builder $q) => $q->whereNull('graduation_year_to')->orWhere('graduation_year_to', '>=', $year ?? PHP_INT_MAX));
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /** "Everyone", "School of Science", "BSc Biology, class of 2020–2024" … for staff screens. */
    public function audienceLabel(): string
    {
        $parts = array_filter([
            $this->programme?->name ?? $this->department?->name ?? $this->school?->name,
            match (true) {
                $this->graduation_year_from && $this->graduation_year_to && $this->graduation_year_from === $this->graduation_year_to => "class of {$this->graduation_year_from}",
                $this->graduation_year_from && $this->graduation_year_to => "class of {$this->graduation_year_from}–{$this->graduation_year_to}",
                (bool) $this->graduation_year_from => "graduated {$this->graduation_year_from} or later",
                (bool) $this->graduation_year_to => "graduated {$this->graduation_year_to} or earlier",
                default => null,
            },
        ]);

        return $parts === [] ? 'All alumni' : ucfirst(implode(', ', $parts));
    }
}

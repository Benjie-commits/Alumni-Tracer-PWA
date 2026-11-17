<?php

namespace App\Models;

use App\Enums\JobType;
use App\Models\Concerns\TargetsAlumni;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A job or internship shown to the alumni it is aimed at (FR-5). */
#[Fillable([
    'title', 'organisation', 'type', 'location', 'description', 'how_to_apply', 'closes_on',
    'school_id', 'department_id', 'programme_id', 'graduation_year_from', 'graduation_year_to',
    'published_at', 'created_by',
])]
class JobPosting extends Model
{
    use HasFactory, TargetsAlumni;

    protected function casts(): array
    {
        return [
            'type' => JobType::class,
            'closes_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still taking applications: no closing date, or it has not passed (Uganda time). */
    public function scopeOpen(Builder $query): Builder
    {
        $today = now(config('sunates.timezone'))->toDateString();

        return $query->where(fn (Builder $q) => $q->whereNull('closes_on')->orWhere('closes_on', '>=', $today));
    }
}

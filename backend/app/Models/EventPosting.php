<?php

namespace App\Models;

use App\Models\Concerns\TargetsAlumni;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An event notice shown to the alumni it is aimed at (FR-5). */
#[Fillable([
    'title', 'description', 'starts_at', 'ends_at', 'venue', 'online_url',
    'school_id', 'department_id', 'programme_id', 'graduation_year_from', 'graduation_year_to',
    'published_at', 'created_by',
])]
class EventPosting extends Model
{
    use HasFactory, TargetsAlumni;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
    }

    /** Not over yet: it ends (or, with no end time, starts) in the future. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereRaw('COALESCE(ends_at, starts_at) >= ?', [now()]);
    }
}

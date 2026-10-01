<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['milestone_months', 'title', 'is_active', 'window_days', 'current_version_id'])]
class TracerSurveyCycle extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TracerSurveyVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TracerSurveyVersion::class, 'current_version_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('current_version_id');
    }

    public function windowDays(): int
    {
        return $this->window_days ?? (int) config('sunates.surveys.window_days');
    }

    /** "6 months", "1 year", "3 years": how long after graduation this survey asks about. */
    public function periodLabel(): string
    {
        $months = $this->milestone_months;

        if ($months % 12 === 0) {
            $years = intdiv($months, 12);

            return $years === 1 ? '1 year' : "{$years} years";
        }

        return "{$months} months";
    }
}

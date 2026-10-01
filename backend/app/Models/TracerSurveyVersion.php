<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable revision of a survey's questions. Responses point at the version they were answered against.
 */
#[Fillable(['tracer_survey_cycle_id', 'version', 'definition', 'definition_hash'])]
class TracerSurveyVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(TracerSurveyCycle::class, 'tracer_survey_cycle_id');
    }

    /** @return list<array<string, mixed>> */
    public function questions(): array
    {
        return $this->definition['questions'] ?? [];
    }

    public function intro(): ?string
    {
        return $this->definition['intro'] ?? null;
    }
}

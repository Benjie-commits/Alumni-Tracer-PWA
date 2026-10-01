<?php

namespace App\Models;

use App\Enums\SurveyInvitationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tracer_survey_cycle_id', 'alumni_profile_id', 'token', 'status', 'due_at', 'expires_at',
    'sent_at', 'reminders_sent', 'last_reminded_at', 'completed_at',
])]
class SurveyInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SurveyInvitationStatus::class,
            'due_at' => 'datetime',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(TracerSurveyCycle::class, 'tracer_survey_cycle_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(SurveyResponse::class);
    }

    /** Still answerable: not completed, and the window has not closed. */
    public function isOpen(): bool
    {
        return $this->status->isOpen() && $this->expires_at->isFuture();
    }

    /** The link sent to the alumnus. Short on purpose: it goes in an SMS. */
    public function url(): string
    {
        return config('sunates.pwa_url').'/s/'.$this->token;
    }
}

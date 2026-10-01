<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'survey_invitation_id', 'tracer_survey_version_id', 'alumni_profile_id', 'submission_id', 'answers',
    'employment_status', 'further_study_status', 'ta_interest', 'submitted_at',
])]
class SurveyResponse extends Model
{
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'ta_interest' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class, 'survey_invitation_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TracerSurveyVersion::class, 'tracer_survey_version_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }
}

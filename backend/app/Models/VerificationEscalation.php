<?php

namespace App\Models;

use App\Enums\EscalationStatus;
use App\Enums\VerificationResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'request_reference', 'organisation', 'requester_name', 'requester_email', 'requester_phone',
    'subject_name', 'subject_graduation_year', 'subject_programme', 'lookup_result', 'message',
    'status', 'resolved_by', 'resolved_at', 'resolution_note',
])]
class VerificationEscalation extends Model
{
    protected function casts(): array
    {
        return [
            'lookup_result' => VerificationResult::class,
            'status' => EscalationStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's answer to an event notice (FR-5). */
#[Fillable(['event_posting_id', 'user_id', 'response'])]
class EventRsvp extends Model
{
    public const RESPONSES = ['going', 'maybe', 'no'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(EventPosting::class, 'event_posting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

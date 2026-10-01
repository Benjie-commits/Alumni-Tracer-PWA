<?php

namespace App\Models;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'alumni_profile_id', 'channel', 'template', 'to_number', 'status', 'provider', 'provider_message_id',
    'error', 'related_type', 'related_id', 'sent_at', 'delivered_at', 'failed_at',
])]
class NotificationLog extends Model
{
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'template' => MessageTemplate::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** "+256 ••• ••• 456": enough to recognise, not enough to copy. */
    public function maskedNumber(): string
    {
        $number = (string) $this->to_number;

        return $number === '' ? '—' : substr($number, 0, 4).' ••• ••• '.substr($number, -3);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code sent to the contact details the Registrar holds, waiting to be typed back (FR-2).
 */
#[Fillable([
    'token', 'alumni_profile_id', 'code_hash', 'channel', 'destination_hint', 'payload',
    'attempts', 'sends', 'last_sent_at', 'expires_at', 'consumed_at', 'ip_address',
])]
#[Hidden(['code_hash', 'payload'])]
class RegistrationChallenge extends Model
{
    use MassPrunable;

    protected function casts(): array
    {
        return [
            // Holds the password hash and the email/phone they typed: encrypted with the app key.
            'payload' => 'encrypted:array',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('sunates.registration.max_attempts');
    }

    /** Spent or abandoned challenges are deleted after a week (`php artisan model:prune`, scheduled daily). */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subWeek());
    }
}

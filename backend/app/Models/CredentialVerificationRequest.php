<?php

namespace App\Models;

use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One lookup by an employer or partner, and what we answered (spec section 6).
 *
 * Deliberately holds no profile data and has no foreign keys, so the table can live in a separate
 * database (config: sunates.verification.connection) as spec section 9 asks.
 */
#[Fillable([
    'reference', 'channel', 'organisation', 'requester_email', 'query_name', 'query_programme_id',
    'query_graduation_year', 'result', 'matched_profile_id', 'ip_address',
])]
class CredentialVerificationRequest extends Model
{
    public const UPDATED_AT = null;

    public function getConnectionName(): ?string
    {
        return config('sunates.verification.connection') ?: parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'channel' => VerificationChannel::class,
            'result' => VerificationResult::class,
            'created_at' => 'datetime',
        ];
    }

    /** "VER-7K3M9QXD": short enough to read over the phone, no look-alike characters. */
    public static function newReference(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'[random_int(0, 30)];
            }
            $reference = "VER-{$code}";
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }
}

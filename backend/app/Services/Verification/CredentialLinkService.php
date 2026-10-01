<?php

namespace App\Services\Verification;

use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use App\Models\AlumniProfile;
use App\Models\CredentialLink;
use App\Models\CredentialVerificationRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Alumni ... request a verified credential link" (spec section 2.3). The alumnus hands an employer
 * a private link; opening it shows that this person's degree is verified, with no searching. Links
 * expire, can be revoked, and are only issued to someone the Registrar's records confirm.
 */
class CredentialLinkService
{
    /** Enough for a few employers at once without letting links pile up. */
    public const MAX_ACTIVE = 5;

    public function issue(AlumniProfile $profile): CredentialLink
    {
        if (! AlumniProfile::query()->verifiable()->whereKey($profile->id)->exists()) {
            throw ValidationException::withMessages([
                'profile' => 'Your graduation has not been confirmed against the Registrar\'s records yet, so a verification link cannot be created.',
            ]);
        }

        if ($profile->credentialLinks()->active()->count() >= self::MAX_ACTIVE) {
            throw ValidationException::withMessages([
                'profile' => 'You already have '.self::MAX_ACTIVE.' active links. Remove one you no longer need first.',
            ]);
        }

        return $profile->credentialLinks()->create([
            'token' => Str::random(40),
            'expires_at' => now()->addDays((int) config('sunates.verification.link_valid_days')),
            'views' => 0, // the database default is not loaded back into the new model
        ]);
    }

    public function revoke(CredentialLink $link): void
    {
        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now()]);
        }
    }

    /**
     * What an employer sees when they open a link, or null when the link is unknown, expired,
     * revoked, or its owner can no longer be confirmed (all look the same from outside).
     */
    public function open(string $token, ?string $ip): ?VerificationOutcome
    {
        $link = CredentialLink::query()->active()->where('token', $token)->first();

        $profile = $link
            ? AlumniProfile::query()->verifiable()->with('programme')->find($link->alumni_profile_id)
            : null;

        if (! $link || ! $profile) {
            return null;
        }

        $link->forceFill(['views' => $link->views + 1, 'last_viewed_at' => now()])->save();

        $reference = CredentialVerificationRequest::newReference();
        CredentialVerificationRequest::query()->create([
            'reference' => $reference,
            'channel' => VerificationChannel::Link,
            'result' => VerificationResult::Verified,
            'matched_profile_id' => $profile->id,
            'ip_address' => $ip,
        ]);

        return new VerificationOutcome(
            VerificationResult::Verified, $reference, $profile->programme?->name, $profile->graduation_year,
            name: trim($profile->first_name.' '.$profile->last_name),
        );
    }
}

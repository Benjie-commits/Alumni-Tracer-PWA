<?php

namespace App\Support;

/**
 * Registrar spreadsheets and alumni type phone numbers every way imaginable (0700 123 456,
 * 256700123456, +256-700-123-456). Providers need one canonical form: E.164, "+256700123456".
 */
final class PhoneNumber
{
    /**
     * @return string|null E.164 (with the leading +), or null if this cannot be a real number
     */
    public static function toE164(?string $raw, ?string $defaultCountryCode = null): ?string
    {
        $defaultCountryCode ??= (string) config('sunates.messaging.default_country_code', '256');

        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $international = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        if (! $international) {
            if (str_starts_with($digits, '0')) {
                // National format: 0700 123 456 -> 256 700 123 456
                $digits = $defaultCountryCode.substr($digits, 1);
            } elseif (! str_starts_with($digits, $defaultCountryCode) && strlen($digits) <= 9) {
                // Bare subscriber number: 700123456
                $digits = $defaultCountryCode.$digits;
            }
        }

        // E.164 allows at most 15 digits and never starts with 0.
        return preg_match('/^[1-9]\d{7,14}$/', $digits) === 1 ? '+'.$digits : null;
    }

    /**
     * The last nine digits, used to find someone from an inbound message regardless of how the
     * number was originally typed into their profile.
     */
    public static function lastNine(?string $raw): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $raw);

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}

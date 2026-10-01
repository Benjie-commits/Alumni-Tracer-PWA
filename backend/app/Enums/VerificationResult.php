<?php

namespace App\Enums;

enum VerificationResult: string
{
    case Verified = 'verified';
    case NotFound = 'not_found';
    /** More than one graduate fits, so nothing can be confirmed without more detail. */
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Verified',
            self::NotFound => 'Not found',
            self::Ambiguous => 'Could not tell apart',
        };
    }
}

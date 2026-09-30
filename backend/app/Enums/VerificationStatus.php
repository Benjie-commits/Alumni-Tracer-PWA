<?php

namespace App\Enums;

enum VerificationStatus: string
{
    /** Imported from Registrar records; no alumnus has claimed it yet. */
    case Unclaimed = 'unclaimed';
    /** Self-declared alumnus who matched no Registrar record; awaiting staff review. */
    case Pending = 'pending';
    /** Matched against Registrar records, or approved by staff. */
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Unclaimed => 'Unclaimed',
            self::Pending => 'Pending review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }
}

<?php

namespace App\Enums;

/** What a staff member found when they looked for a non-responsive alumnus by hand. */
enum FollowUpOutcome: string
{
    case Updated = 'updated';
    case Confirmed = 'confirmed';
    case NotFound = 'not_found';
    case Unsure = 'unsure';

    public function label(): string
    {
        return match ($this) {
            self::Updated => 'Found them and updated the record',
            self::Confirmed => 'Found them; the record was already right',
            self::NotFound => 'Could not find them',
            self::Unsure => 'Found someone, but not sure it is them',
        };
    }
}

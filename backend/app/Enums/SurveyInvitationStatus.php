<?php

namespace App\Enums;

enum SurveyInvitationStatus: string
{
    /** Created and open in the app, but no message has gone out (yet, or no reachable channel). */
    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Completed = 'completed';
    /** The window closed before the alumnus answered. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Not yet sent',
            self::Sent => 'Sent',
            self::Completed => 'Completed',
            self::Expired => 'Expired',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Scheduled || $this === self::Sent;
    }
}

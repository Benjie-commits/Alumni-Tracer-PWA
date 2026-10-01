<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Queued = 'queued';
    /** Accepted by the provider; delivery not yet confirmed. */
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    /** Deliberately not sent: opted out, no usable number, or nothing configured. */
    case Blocked = 'blocked';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Statuses meaning the message got out (used for "have we already messaged them?"). */
    public static function delivered(): array
    {
        return [self::Sent, self::Delivered, self::Read];
    }
}

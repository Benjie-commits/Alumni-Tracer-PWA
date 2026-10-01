<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
        };
    }
}

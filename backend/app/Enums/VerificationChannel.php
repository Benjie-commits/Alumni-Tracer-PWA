<?php

namespace App\Enums;

enum VerificationChannel: string
{
    /** The web page employers use. */
    case Portal = 'portal';
    /** A partner institution calling the JSON endpoint. */
    case Api = 'api';
    /** An employer opening a link an alumnus gave them. */
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Portal => 'Web portal',
            self::Api => 'API',
            self::Link => 'Alumnus link',
        };
    }
}

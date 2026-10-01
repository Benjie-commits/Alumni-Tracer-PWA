<?php

namespace App\Enums;

enum EscalationStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

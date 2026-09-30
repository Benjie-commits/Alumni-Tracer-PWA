<?php

namespace App\Enums;

enum FurtherStudyStatus: string
{
    case None = 'none';
    case Studying = 'studying';
    case Planned = 'planned';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not studying',
            self::Studying => 'Currently studying',
            self::Planned => 'Planning to study',
        };
    }
}

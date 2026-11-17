<?php

namespace App\Enums;

enum JobType: string
{
    case Job = 'job';
    case Internship = 'internship';

    public function label(): string
    {
        return match ($this) {
            self::Job => 'Job',
            self::Internship => 'Internship',
        };
    }
}

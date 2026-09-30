<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Employed = 'employed';
    case SelfEmployed = 'self_employed';
    case Internship = 'internship';
    case Volunteer = 'volunteer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Employed => 'Employed',
            self::SelfEmployed => 'Self-employed',
            self::Internship => 'Internship',
            self::Volunteer => 'Volunteer',
            self::Other => 'Other',
        };
    }
}

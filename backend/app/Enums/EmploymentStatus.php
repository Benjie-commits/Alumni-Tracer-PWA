<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Employed = 'employed';
    case SelfEmployed = 'self_employed';
    case Unemployed = 'unemployed';
    case FurtherStudy = 'further_study';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Employed => 'Employed',
            self::SelfEmployed => 'Self-employed / entrepreneur',
            self::Unemployed => 'Seeking work',
            self::FurtherStudy => 'In further study',
            self::Other => 'Other',
        };
    }
}

<?php

namespace App\Support;

use Closure;

/**
 * Soroti University student numbers: 10 digits, e.g. 2401600227.
 *   24     the cohort (year of entry, 2024)
 *   01600  Soroti University's code: the same in every student number
 *   227    the student's own number
 * People often type spaces or dashes ("24 016 00227"), so those are removed before checking.
 */
final class StudentNumber
{
    public const UNIVERSITY_CODE = '01600';

    public const PATTERN = '/^\d{2}'.self::UNIVERSITY_CODE.'\d{3}$/';

    public const HINT = '10 digits, e.g. 2401600227: your cohort, then 01600, then your own 3 digits.';

    public static function normalise(?string $value): string
    {
        return (string) preg_replace('/[\s\-\/.]+/', '', (string) $value);
    }

    public static function isValid(?string $value): bool
    {
        $n = self::normalise($value);

        return (bool) preg_match(self::PATTERN, $n) && self::cohortYear($n) <= (int) now()->format('Y');
    }

    /** 2401600227 -> 2024. */
    public static function cohortYear(string $number): int
    {
        return 2000 + (int) substr(self::normalise($number), 0, 2);
    }

    /** 2401600227 -> "227". */
    public static function uniquePart(string $number): string
    {
        return substr(self::normalise($number), -3);
    }

    /** A validation rule for forms. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! self::isValid($value)) {
                $fail('Enter your student number as '.self::HINT);
            }
        };
    }
}

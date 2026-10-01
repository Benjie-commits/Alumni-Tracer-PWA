<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * No one wants a survey SMS at 2am. Sends are held between the configured hours (Uganda time).
 */
final class QuietHours
{
    public static function isQuiet(?CarbonInterface $now = null): bool
    {
        [$start, $end] = self::window();
        $hour = self::local($now)->hour;

        // The window normally wraps midnight (19 -> 8); a same-day window (e.g. 12 -> 14) also works.
        return $start > $end ? ($hour >= $start || $hour < $end) : ($hour >= $start && $hour < $end);
    }

    /** Seconds until sending is allowed again (0 when it already is). */
    public static function secondsUntilOpen(?CarbonInterface $now = null): int
    {
        if (! self::isQuiet($now)) {
            return 0;
        }

        [, $end] = self::window();
        $local = self::local($now);
        $open = $local->copy()->setTime($end, 0);
        if ($open->lessThanOrEqualTo($local)) {
            $open->addDay();
        }

        return max(1, (int) $local->diffInSeconds($open));
    }

    /** @return array{0: int, 1: int} */
    private static function window(): array
    {
        return [
            (int) config('sunates.messaging.quiet_hours.start'),
            (int) config('sunates.messaging.quiet_hours.end'),
        ];
    }

    private static function local(?CarbonInterface $now): CarbonInterface
    {
        return Carbon::instance($now ?? now())->setTimezone(config('sunates.timezone'));
    }
}

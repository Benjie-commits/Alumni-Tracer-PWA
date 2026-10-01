<?php

namespace Tests\Unit;

use App\Support\QuietHours;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuietHoursTest extends TestCase
{
    private function kampala(string $time): Carbon
    {
        return Carbon::parse("2026-10-05 {$time}", 'Africa/Kampala');
    }

    public function test_daytime_is_open(): void
    {
        foreach (['08:00', '09:30', '12:00', '18:59'] as $time) {
            $this->assertFalse(QuietHours::isQuiet($this->kampala($time)), "{$time} should be open");
        }
    }

    public function test_evening_and_night_are_quiet(): void
    {
        foreach (['19:00', '21:30', '23:59', '00:00', '03:15', '07:59'] as $time) {
            $this->assertTrue(QuietHours::isQuiet($this->kampala($time)), "{$time} should be quiet");
        }
    }

    public function test_it_is_judged_in_uganda_time_not_server_time(): void
    {
        // 06:00 UTC is 09:00 in Kampala (open); 17:00 UTC is 20:00 in Kampala (quiet).
        $this->assertFalse(QuietHours::isQuiet(Carbon::parse('2026-10-05 06:00', 'UTC')));
        $this->assertTrue(QuietHours::isQuiet(Carbon::parse('2026-10-05 17:00', 'UTC')));
    }

    public function test_seconds_until_open_when_already_open_is_zero(): void
    {
        $this->assertSame(0, QuietHours::secondsUntilOpen($this->kampala('10:00')));
    }

    public function test_seconds_until_open_from_the_evening_runs_to_next_morning(): void
    {
        // 20:00 -> 08:00 next day = 12 hours.
        $this->assertSame(12 * 3600, QuietHours::secondsUntilOpen($this->kampala('20:00')));
    }

    public function test_seconds_until_open_from_the_small_hours_runs_to_this_morning(): void
    {
        // 03:00 -> 08:00 same day = 5 hours.
        $this->assertSame(5 * 3600, QuietHours::secondsUntilOpen($this->kampala('03:00')));
    }

    public function test_a_same_day_window_works_too(): void
    {
        config(['sunates.messaging.quiet_hours' => ['start' => 12, 'end' => 14]]);

        $this->assertTrue(QuietHours::isQuiet($this->kampala('13:00')));
        $this->assertFalse(QuietHours::isQuiet($this->kampala('11:59')));
        $this->assertFalse(QuietHours::isQuiet($this->kampala('14:00')));
    }
}

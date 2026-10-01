<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work (spec section 5.3: Laravel scheduler and queue workers)
|--------------------------------------------------------------------------
| Production needs two things running: a cron entry calling `php artisan schedule:run` every minute,
| and a queue worker (`php artisan queue:work`) kept alive by Supervisor or systemd.
| Times are Uganda time. Messages are additionally held during quiet hours (see QuietHours).
*/

$timezone = config('sunates.timezone');

// Create due survey invitations, send reminders, close expired surveys.
Schedule::command('sunates:surveys')->dailyAt('09:00')->timezone($timezone)->withoutOverlapping()->onOneServer();

// Ask alumni with out-of-date records to update them (FR-7). Weekly is plenty; frequency caps live in config.
Schedule::command('sunates:nudges')->weeklyOn(2, '10:00')->timezone($timezone)->withoutOverlapping()->onOneServer();

// Poll the SMS provider for delivery receipts.
Schedule::command('sunates:sync-delivery-status')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// SorotiUniERP (FR-9): turn newly graduated students into alumni records. A no-op while the integration is off.
Schedule::command('sunates:sync-erp --scheduled')->dailyAt(config('sunates.erp.sync_at'))->timezone($timezone)->withoutOverlapping()->onOneServer();

// Retention: old lookup logs, resolved enquiries and dead credential links.
Schedule::command('sunates:prune-verification-data')->monthlyOn(1, '03:00')->timezone($timezone)->onOneServer();

// Housekeeping: expired API tokens and finished job records.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->weekly()->onOneServer();

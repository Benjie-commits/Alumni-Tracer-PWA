<?php

namespace App\Console\Commands;

use App\Services\Surveys\SurveyScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sunates:surveys {--dry-run : Show what would happen without creating invitations or sending anything}')]
#[Description('Create due tracer-survey invitations, send reminders and close expired surveys (runs daily)')]
class RunSurveyCycle extends Command
{
    public function handle(SurveyScheduler $scheduler): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = $dryRun ? 0 : $scheduler->expireOverdue();
        $created = $scheduler->schedule(dryRun: $dryRun);
        $reminders = $scheduler->sendReminders(dryRun: $dryRun);

        $this->info($dryRun ? 'DRY RUN: nothing was created or sent.' : 'Survey cycle complete.');
        $this->table(['Milestone (months)', $dryRun ? 'Would invite' : 'Invited'], collect($created)->map(fn ($n, $months) => [$months, $n])->values()->all());
        $this->line(($dryRun ? 'Would remind: ' : 'Reminders queued: ').$reminders);
        $this->line("Closed as expired: {$expired}");

        if ($created === []) {
            $this->warn('No active surveys found. Run: php artisan sunates:sync-surveys');
        }

        return self::SUCCESS;
    }
}

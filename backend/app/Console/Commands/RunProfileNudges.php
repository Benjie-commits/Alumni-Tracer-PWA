<?php

namespace App\Console\Commands;

use App\Services\Nudges\ProfileNudger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sunates:nudges {--dry-run : Show how many alumni would be nudged without sending anything}')]
#[Description('Nudge alumni with out-of-date records to confirm their details by WhatsApp or SMS (FR-7)')]
class RunProfileNudges extends Command
{
    public function handle(ProfileNudger $nudger): int
    {
        if (! config('sunates.nudges.enabled')) {
            $this->warn('Nudges are switched off (NUDGES_ENABLED=false).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $nudger->run(dryRun: $dryRun);

        $this->info($dryRun ? 'DRY RUN: nothing was sent.' : 'Nudges queued.');
        $this->line("Records due a nudge: {$result['eligible']}");
        $this->line(($dryRun ? 'Would send today: ' : 'Queued today: ').$result['queued'].' (daily limit '.config('sunates.nudges.daily_limit').')');

        return self::SUCCESS;
    }
}

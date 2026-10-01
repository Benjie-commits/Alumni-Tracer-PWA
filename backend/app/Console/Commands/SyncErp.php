<?php

namespace App\Console\Commands;

use App\Enums\ErpSyncStatus;
use App\Services\Erp\ErpSourceException;
use App\Services\Erp\ErpSyncBusy;
use App\Services\Erp\ErpSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sunates:sync-erp {--dry-run : Show what would change and save nothing} {--full : Read every graduate, not just those changed since the last sync} {--scheduled : Quietly do nothing when the integration is off (used by the scheduler)}')]
#[Description('Turn graduating students in SorotiUniERP into alumni records (FR-9)')]
class SyncErp extends Command
{
    public function handle(ErpSyncService $sync): int
    {
        if (! $sync->isEnabled() && $this->option('scheduled')) {
            return self::SUCCESS; // standalone mode: nothing to do, nothing to report
        }

        try {
            $run = $sync->execute($sync->start(
                $this->option('scheduled') ? 'scheduled' : 'cli',
                dryRun: (bool) $this->option('dry-run'),
                full: (bool) $this->option('full'),
            ));
        } catch (ErpSourceException|ErpSyncBusy $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($run->status === ErpSyncStatus::Failed) {
            $this->error('The sync failed: '.$run->error);

            return self::FAILURE;
        }

        $this->line(($run->dry_run ? 'Preview (nothing saved): ' : 'Synced: ')
            ."{$run->fetched} read, {$run->created} new, {$run->updated} updated, {$run->unchanged} already up to date, "
            ."{$run->not_graduated} not graduated yet, {$run->rejected} rejected");

        foreach (array_slice($run->report['errors'] ?? [], 0, 20) as $problem) {
            $this->warn($problem['message']);
        }

        return self::SUCCESS;
    }
}

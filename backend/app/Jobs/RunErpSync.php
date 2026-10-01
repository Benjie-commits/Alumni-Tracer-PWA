<?php

namespace App\Jobs;

use App\Models\ErpSyncRun;
use App\Services\Erp\ErpSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A sync started from the console. It runs on the queue so the page answers at once however many
 * graduates the ERP holds; the page polls the run record to show progress.
 */
class RunErpSync implements ShouldQueue
{
    use Queueable;

    // A failed sync is recorded and shown to ICT, who start it again; retrying silently would hide the failure.
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $runId) {}

    public function handle(ErpSyncService $sync): void
    {
        if ($run = ErpSyncRun::query()->find($this->runId)) {
            $sync->execute($run);
        }
    }
}

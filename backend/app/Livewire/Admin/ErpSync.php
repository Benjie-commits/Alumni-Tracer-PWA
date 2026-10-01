<?php

namespace App\Livewire\Admin;

use App\Enums\RoleSlug;
use App\Jobs\RunErpSync;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\ErpSyncRun;
use App\Services\Erp\ErpSourceException;
use App\Services\Erp\ErpSyncBusy;
use App\Services\Erp\ErpSyncService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * FR-9 from the Directorate of ICT's side: is the SorotiUniERP hook on, did the last sync work, and
 * what did it change. The Registrar can read it (they own record quality); only ICT starts anything.
 */
#[Layout('layouts::admin')]
#[Title('SorotiUniERP sync')]
class ErpSync extends Component
{
    use AuthorizesStaff;

    public ?bool $checkOk = null;

    public ?string $checkMessage = null;

    public ?string $started = null;

    /** The run whose problems are expanded. */
    public ?int $openRun = null;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function check(ErpSyncService $sync): void
    {
        $this->authorizeIctAdmin();

        $result = $sync->check();
        $this->checkOk = $result->ok;
        $this->checkMessage = $result->message;
    }

    /** Dry run: reads the ERP and reports what would change, saving nothing. */
    public function preview(ErpSyncService $sync): void
    {
        $this->queue($sync, dryRun: true, full: false);
    }

    public function syncNow(ErpSyncService $sync): void
    {
        $this->queue($sync, dryRun: false, full: false);
    }

    /** Read every graduate rather than only recent changes: for after a fault, or a changed field map. */
    public function syncEverything(ErpSyncService $sync): void
    {
        $this->queue($sync, dryRun: false, full: true);
    }

    public function toggle(int $runId): void
    {
        $this->openRun = $this->openRun === $runId ? null : $runId;
    }

    private function queue(ErpSyncService $sync, bool $dryRun, bool $full): void
    {
        $this->authorizeIctAdmin();
        $this->resetErrorBag();
        $this->started = null;

        try {
            $run = $sync->start('manual', $dryRun, $full, auth()->user());
        } catch (ErpSourceException|ErpSyncBusy $e) {
            $this->addError('sync', $e->getMessage());

            return;
        }

        RunErpSync::dispatch($run->id);

        $this->started = $dryRun
            ? 'Preview started. Nothing will be saved; the result appears below in a moment.'
            : 'Sync started. The result appears below in a moment.';
    }

    public function render()
    {
        $this->authorizeManager();

        $driver = (string) config('sunates.erp.driver');

        return view('livewire.admin.erp-sync', [
            'driver' => $driver,
            'enabled' => $driver !== 'none',
            'target' => $this->target($driver),
            'canRun' => auth()->user()->hasRole(RoleSlug::IctAdmin),
            'runs' => ErpSyncRun::query()->with('starter:id,name')->latest('id')->limit(15)->get(),
            'busy' => ErpSyncRun::active()->exists(),
            'last' => ErpSyncRun::lastSuccessful(),
        ]);
    }

    /** Where the hook reads from, for display. Never shows a credential. */
    private function target(string $driver): ?string
    {
        return match ($driver) {
            'rest' => ($host = parse_url((string) config('sunates.erp.rest.base_url'), PHP_URL_HOST)) ? "REST API at {$host}" : 'REST API (address not set)',
            'database' => 'Database view '.config('sunates.erp.database.table').' on '.(config('sunates.erp.database.connection') ?: 'the main connection'),
            default => null,
        };
    }
}

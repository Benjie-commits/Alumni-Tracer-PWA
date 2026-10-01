<?php

namespace App\Services\Erp;

use App\Enums\ErpSyncStatus;
use App\Enums\RecordSource;
use App\Models\ErpSyncRun;
use App\Models\User;
use App\Services\Erp\Contracts\GraduateSource;
use App\Services\Import\AlumniImportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * FR-9: a graduating student's ERP record becomes an alumni profile without anyone re-typing it.
 *
 * The ERP is read, each record is mapped to our columns, and the rows go through exactly the rules a
 * Registrar spreadsheet goes through (AlumniImportService): matched on student number, Registrar-owned
 * fields refreshed, an alumnus's own contact details never overwritten, nothing deleted. A run is
 * recorded whether it works or not, so ICT can see what last night's sync did.
 */
class ErpSyncService
{
    private const LOCK = 'sunates:erp-sync';

    public function __construct(
        private readonly GraduateSource $source,
        private readonly GraduateMapper $mapper,
        private readonly AlumniImportService $import,
    ) {}

    public function isEnabled(): bool
    {
        return config('sunates.erp.driver') !== 'none';
    }

    /**
     * Register a run that is about to happen (by the scheduler, the command, or a queued job).
     *
     * @throws ErpSourceException when the integration is switched off
     * @throws ErpSyncBusy when another run is still going
     */
    public function start(string $trigger, bool $dryRun = false, bool $full = false, ?User $by = null): ErpSyncRun
    {
        if (! $this->isEnabled()) {
            throw new ErpSourceException('The SorotiUniERP integration is switched off (ERP_DRIVER=none).');
        }

        $this->failDeadRuns();

        if (ErpSyncRun::active()->exists()) {
            throw new ErpSyncBusy('A sync is already waiting or running; let it finish first.');
        }

        return ErpSyncRun::create([
            'driver' => (string) config('sunates.erp.driver'),
            'trigger' => $trigger,
            'started_by' => $by?->id,
            'dry_run' => $dryRun,
            'full' => $full,
            'status' => ErpSyncStatus::Queued,
        ]);
    }

    /** Do the work for a run created by start(). Always leaves the run finished or failed. */
    public function execute(ErpSyncRun $run): ErpSyncRun
    {
        if ($run->status !== ErpSyncStatus::Queued) {
            return $run; // already picked up (a duplicate job, or a run that was marked dead)
        }

        $lock = Cache::lock(self::LOCK, (int) config('sunates.erp.stale_run_minutes') * 60);
        if (! $lock->get()) {
            return $this->fail($run, 'Another sync was working at the same moment.');
        }

        try {
            $run->forceFill(['status' => ErpSyncStatus::Running, 'started_at' => now()])->save();

            $since = $run->full ? null : ErpSyncRun::lastCursor()?->subHours((int) config('sunates.erp.overlap_hours'));
            $run->since = $since;

            // The ERP is asked in Uganda time with an explicit offset, which reads the same on any server.
            [$rows, $identities, $fetched, $notGraduated, $newest] = $this->read($since?->copy()->timezone(config('sunates.timezone')));

            $report = $this->import->importRows($rows, $run->dry_run, RecordSource::Erp);

            // Name the student on every problem: "row 412" means nothing to someone looking at the ERP.
            $label = fn (array $items) => array_map(
                fn (array $i) => ['row' => $i['row'], 'message' => ($identities[$i['row']] ?? 'unknown').': '.$i['message']],
                $items
            );

            $run->forceFill([
                'status' => ErpSyncStatus::Succeeded,
                'fetched' => $fetched,
                'not_graduated' => $notGraduated,
                'created' => $report->created,
                'updated' => $report->updated,
                'unchanged' => $report->unchanged,
                'rejected' => $report->skipped,
                // The next sync starts where this one ended, but only if nothing was turned away: a
                // record we rejected must be offered again next time, not skipped past for good.
                'cursor' => ($run->dry_run || $report->skipped > 0) ? null : $newest,
                'report' => [
                    'errors' => $label($report->errors),
                    'warnings' => $label($report->warnings),
                    'reference_created' => $report->referenceCreated,
                ],
                'finished_at' => now(),
            ])->save();

            return $run;
        } catch (ErpSourceException $e) {
            return $this->fail($run, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->fail($run, 'The sync stopped on an unexpected error and saved nothing. ICT can find the details in the application log.');
        } finally {
            $lock->release();
        }
    }

    /** Can we reach the ERP, and does a record look the way the field map expects? Saves nothing. */
    public function check(): SourceCheck
    {
        try {
            $first = null;
            foreach ($this->source->fetch(null, 1) as $record) {
                $first = $record;
                break;
            }
        } catch (ErpSourceException $e) {
            return new SourceCheck(false, $e->getMessage());
        }

        if ($first === null) {
            return new SourceCheck(true, 'Connected, but the ERP returned no graduates. If that is not expected, check what the ERP is filtering on.');
        }

        $missing = $this->mapper->missingRequired($first);
        if ($missing !== []) {
            return new SourceCheck(false, 'Connected, but the first record has nothing for '.implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $missing)).'. The field map (ERP_FIELD_MAP) probably does not match the ERP.');
        }

        return new SourceCheck(true, 'Connected. The first record has every field we need.');
    }

    /**
     * Read the whole feed before touching our database, so a failure half-way through the ERP's pages
     * saves nothing and no transaction stays open while we wait on the network.
     *
     * @return array{0: array<int, array<string, string>>, 1: array<int, string>, 2: int, 3: int, 4: ?Carbon}
     */
    private function read(?Carbon $since): array
    {
        $max = (int) config('sunates.erp.max_records');
        $rows = [];
        $identities = [];
        $fetched = 0;
        $notGraduated = 0;
        $newest = null;

        foreach ($this->source->fetch($since) as $record) {
            if (++$fetched > $max) {
                throw new ErpSourceException("The ERP sent more than {$max} records. That is almost certainly a missing filter or a paging fault, so nothing was saved.");
            }

            if (($changed = $this->mapper->changedAt($record)) !== null && ($newest === null || $changed->gt($newest))) {
                // Stored in the application's own zone: a Carbon in another zone is saved as its wall-clock
                // time and read back as if it were ours, which would shift the saved point by hours.
                $newest = Carbon::instance($changed)->timezone(config('app.timezone'));
            }

            $row = $this->mapper->map($record);
            if ($row === null) {
                $notGraduated++;

                continue;
            }

            $position = count($rows) + 1;
            $rows[$position] = $row;
            $identities[$position] = $row['student_number'] !== '' ? $row['student_number'] : 'no student number';
        }

        // A timestamp from the future (a clock fault in the ERP) must not push the next sync past real changes.
        if ($newest !== null && $newest->isFuture()) {
            $newest = now();
        }

        return [$rows, $identities, $fetched, $notGraduated, $newest];
    }

    private function fail(ErpSyncRun $run, string $message): ErpSyncRun
    {
        $run->forceFill([
            'status' => ErpSyncStatus::Failed,
            'error' => mb_strimwidth($message, 0, 1000, '…'),
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    /** A run left "running" by a stopped worker must not block every later sync. */
    private function failDeadRuns(): void
    {
        ErpSyncRun::query()
            ->whereIn('status', [ErpSyncStatus::Queued, ErpSyncStatus::Running])
            ->where('created_at', '<', now()->subMinutes((int) config('sunates.erp.stale_run_minutes')))
            ->update([
                'status' => ErpSyncStatus::Failed,
                'error' => 'This sync never finished (the queue worker probably stopped).',
                'finished_at' => now(),
            ]);
    }
}

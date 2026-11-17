<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErpSyncStatus;
use App\Http\Controllers\Controller;
use App\Services\Erp\ErpSyncBusy;
use App\Services\Erp\ErpSyncService;
use App\Services\Erp\GraduateMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where SorotiUniERP pushes graduating students when it is ready to (spec section 7.1, FR-9).
 *
 * Authenticated by a key only the ERP holds (EnsureErpToken). The ERP sends its own records; the field
 * map says what its fields mean, and the records go through the same rules as a spreadsheet import.
 */
class ErpIngestController extends Controller
{
    /** "Can I reach you, and is my key right?" The ERP team's first call. */
    public function status(GraduateMapper $mapper): JsonResponse
    {
        return response()->json(['data' => [
            'ok' => true,
            'max_batch' => (int) config('sunates.erp.inbound.max_batch'),
            'required_fields' => array_map(fn (string $c) => $mapper->field($c) ?? $c, GraduateMapper::REQUIRED),
            'accepted_fields' => array_values(array_filter(array_map(fn (string $c) => $mapper->field($c), GraduateMapper::COLUMNS))),
            'status_field' => $mapper->field('status'),
        ]]);
    }

    public function graduates(Request $request, ErpSyncService $sync): JsonResponse
    {
        $max = (int) config('sunates.erp.inbound.max_batch');

        $data = $request->validate([
            'graduates' => ['required', 'array', 'min:1', "max:{$max}"],
            'graduates.*' => ['array'],
            'dry_run' => ['sometimes', 'boolean'],
        ], [
            'graduates.max' => "Send at most {$max} graduates in one request; split larger lists into batches.",
        ]);

        try {
            $run = $sync->ingest($data['graduates'], (bool) ($data['dry_run'] ?? false));
        } catch (ErpSyncBusy $e) {
            return response()->json(['message' => $e->getMessage()], 409, ['Retry-After' => '60']);
        }

        if ($run->status === ErpSyncStatus::Failed) {
            return response()->json(['message' => $run->error], 500);
        }

        return response()->json(['data' => [
            'run_id' => $run->id,
            'dry_run' => $run->dry_run,
            'received' => $run->fetched,
            'created' => $run->created,
            'updated' => $run->updated,
            'unchanged' => $run->unchanged,
            'not_graduated' => $run->not_graduated,
            'rejected' => $run->rejected,
            // Each problem names the student ("SU/2026/009: Missing last name."), so the ERP team can fix and resend.
            'problems' => array_column($run->report['errors'] ?? [], 'message'),
        ]]);
    }
}

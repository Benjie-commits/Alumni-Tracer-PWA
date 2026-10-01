<?php

namespace App\Models;

use App\Enums\ErpSyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'driver', 'trigger', 'started_by', 'dry_run', 'full', 'status', 'since', 'cursor',
    'fetched', 'not_graduated', 'created', 'updated', 'unchanged', 'rejected',
    'error', 'report', 'started_at', 'finished_at',
])]
class ErpSyncRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ErpSyncStatus::class,
            'dry_run' => 'boolean',
            'full' => 'boolean',
            'since' => 'datetime',
            'cursor' => 'datetime',
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** Runs that are still waiting or working (and not long dead). */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [ErpSyncStatus::Queued, ErpSyncStatus::Running])
            ->where('created_at', '>=', now()->subMinutes((int) config('sunates.erp.stale_run_minutes')));
    }

    /** The newest change seen by the last real, successful sync: where the next one picks up. */
    public static function lastCursor(): ?Carbon
    {
        return static::query()
            ->where('status', ErpSyncStatus::Succeeded)
            ->where('dry_run', false)
            ->whereNotNull('cursor')
            ->latest('id')
            ->value('cursor');
    }

    public static function lastSuccessful(): ?self
    {
        return static::query()->where('status', ErpSyncStatus::Succeeded)->where('dry_run', false)->latest('id')->first();
    }
}

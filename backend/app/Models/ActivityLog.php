<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sensitive staff action: a record edited, a verification decision, an export of personal data,
 * an account change. See App\Support\Audit for what is (and is deliberately not) recorded.
 */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'changed_fields', 'meta', 'ip_address'])]
class ActivityLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Kept for sunates.audit.retention_days, then deleted by `php artisan model:prune` (scheduled daily). */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('sunates.audit.retention_days')));
    }

    /** Human wording for the console. */
    public function label(): string
    {
        // Indexed directly: the action names contain dots, which config() would read as nesting.
        return config('sunates.audit.labels')[$this->action] ?? str_replace(['.', '_'], [': ', ' '], $this->action);
    }
}

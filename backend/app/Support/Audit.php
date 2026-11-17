<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a sensitive staff action in the audit trail.
 *
 * What goes in: who (the signed-in staff member), what (an action name), to which record (type + id),
 * WHICH fields changed, and non-personal context such as export filters or row counts. What never goes
 * in: field values, names, contact details, search text or passwords, so the trail is safe to keep for
 * years and to show to ICT without becoming a second copy of the personal data.
 */
final class Audit
{
    /** Bookkeeping columns that say nothing about what a person did. */
    private const IGNORED_FIELDS = ['updated_at', 'created_at', 'remember_token'];

    /**
     * @param  list<string>  $changedFields  attribute names only
     * @param  array<string, scalar|null|list<scalar>>  $meta  non-personal context
     */
    public static function record(string $action, ?Model $subject = null, array $changedFields = [], array $meta = []): ActivityLog
    {
        $fields = array_values(array_diff($changedFields, self::IGNORED_FIELDS));

        return ActivityLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changed_fields' => $fields === [] ? null : $fields,
            'meta' => $meta === [] ? null : $meta,
            'ip_address' => request()?->ip(),
        ]);
    }

    /** The names of the attributes a save just changed (call right after save()). */
    public static function changesOf(Model $model): array
    {
        return array_keys($model->getChanges());
    }
}

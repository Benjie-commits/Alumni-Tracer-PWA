<?php

namespace App\Services\Followup;

use App\Enums\MessageTemplate;
use App\Enums\NotificationStatus;
use App\Models\AlumniProfile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who staff might look for by hand (spec section 7.4's fallback to LinkedIn monitoring).
 *
 * LinkedIn gives no consented way to watch for job changes, so for alumni who never answer us the spec
 * falls back to "occasional manual staff search for genuinely non-responsive alumni". "Genuinely"
 * is what this class enforces. A person is only suggested if all of these hold:
 *  - the Registrar's records confirm they graduated;
 *  - they have not confirmed their own record for longer than the nudge threshold;
 *  - they have not asked us to stop messaging them on every channel (that is a request to be left alone);
 *  - nobody on staff has already looked for them recently;
 * and either we asked and got nothing (several nudges), or we have no number to ask on.
 */
class FollowUpCandidates
{
    public const UNRESPONSIVE = 'unresponsive';

    public const UNREACHABLE = 'unreachable';

    /**
     * @param  array{programme_id?: mixed, graduation_year?: mixed, search?: mixed}  $filters
     * @return Builder<AlumniProfile>
     */
    public function query(string $reason, array $filters = []): Builder
    {
        $query = $this->eligible();

        $query = $reason === self::UNREACHABLE ? $this->unreachable($query) : $this->unresponsive($query);

        return $query
            ->when($filters['programme_id'] ?? null, fn (Builder $q, $id) => $q->where('programme_id', (int) $id))
            ->when($filters['graduation_year'] ?? null, fn (Builder $q, $year) => $q->where('graduation_year', (int) $year))
            ->search($filters['search'] ?? null, false) // names and student numbers only: contact details stay out of it
            ->orderByDesc('graduation_year')
            ->orderBy('last_name')
            ->orderBy('id');
    }

    /** @return Builder<AlumniProfile> */
    private function eligible(): Builder
    {
        $staleBefore = now()->subDays((int) config('sunates.nudges.stale_after_days'));
        $recheckAfter = now()->subDays((int) config('sunates.followup.recheck_after_days'));

        return AlumniProfile::query()
            // A graduate the Registrar's own records confirm: the one thing worth chasing.
            ->verifiable()
            // Stale by the same definition the nudges use: no confirmation (profile edit or survey) in that long.
            ->whereRaw(
                'GREATEST(COALESCE(profile_updated_at, created_at), COALESCE(last_survey_completed_at, created_at)) < ?',
                [$staleBefore]
            )
            // Stopping every message is a clear request to be left alone, so these people are never suggested.
            ->where(fn (Builder $q) => $q->whereNull('sms_opt_out_at')->orWhereNull('whatsapp_opt_out_at'))
            // Someone already looked; give it time before suggesting them again.
            ->whereDoesntHave('followUpChecks', fn (Builder $q) => $q->where('created_at', '>=', $recheckAfter));
    }

    /**
     * Asked, repeatedly, and silence: at least min_nudges nudges that got out in the last year. Alumni
     * we have never nudged are not "non-responsive", just not asked yet, so they do not appear here.
     *
     * @param  Builder<AlumniProfile>  $query
     * @return Builder<AlumniProfile>
     */
    private function unresponsive(Builder $query): Builder
    {
        [$templates, $statuses] = $this->nudgeFilter();
        $placeholders = fn (array $values) => implode(',', array_fill(0, count($values), '?'));

        return $query->whereRaw(
            "(SELECT COUNT(*) FROM notification_logs WHERE notification_logs.alumni_profile_id = alumni_profiles.id AND notification_logs.template IN ({$placeholders($templates)}) AND notification_logs.status IN ({$placeholders($statuses)}) AND notification_logs.created_at >= ?) >= ?",
            [...$templates, ...$statuses, now()->subYear(), (int) config('sunates.followup.min_nudges')]
        );
    }

    /**
     * No number to nudge on at all, so silence tells us nothing: staff are the only route left.
     *
     * @param  Builder<AlumniProfile>  $query
     * @return Builder<AlumniProfile>
     */
    private function unreachable(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('phone')->orWhere('phone', ''))
            ->where(fn (Builder $q) => $q->whereNull('whatsapp_number')->orWhere('whatsapp_number', ''));
    }

    /**
     * Add each profile's count of nudges that got out in the last year, for display.
     *
     * @param  Builder<AlumniProfile>  $query
     * @return Builder<AlumniProfile>
     */
    public function withNudgeCount(Builder $query): Builder
    {
        [$templates, $statuses] = $this->nudgeFilter();

        return $query->withCount(['notificationLogs as nudges_sent' => fn (Builder $q) => $q
            ->whereIn('template', $templates)
            ->whereIn('status', $statuses)
            ->where('created_at', '>=', now()->subYear())]);
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function nudgeFilter(): array
    {
        return [
            array_map(fn (MessageTemplate $t) => $t->value, MessageTemplate::nudges()),
            array_map(fn (NotificationStatus $s) => $s->value, NotificationStatus::delivered()),
        ];
    }
}

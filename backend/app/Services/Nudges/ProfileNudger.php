<?php

namespace App\Services\Nudges;

use App\Enums\MessageTemplate;
use App\Enums\NotificationStatus;
use App\Enums\VerificationStatus;
use App\Jobs\SendProfileNudge;
use App\Models\AlumniProfile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * FR-7: finds records that are probably out of date and asks the alumnus, by WhatsApp or SMS, to log
 * in and update them.
 *
 * "Out of date" means the alumnus themselves has not touched the record (a profile edit, or a survey
 * answer) for a year. It never looks at anyone's LinkedIn: spec section 7.4 replaces automated
 * checking with an opt-in flow in a later phase.
 *
 * It is deliberately polite: at most one nudge per quarter and three a year, nothing to someone who
 * heard from us in the last fortnight, nothing to people who opted out, and a daily cap so one
 * run cannot burn the SMS budget.
 */
class ProfileNudger
{
    /**
     * @return array{eligible: int, queued: int}
     */
    public function run(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $config = config('sunates.nudges');

        if (! $config['enabled']) {
            return ['eligible' => 0, 'queued' => 0];
        }

        $now = Carbon::instance($now ?? now());
        $query = $this->candidates($now);
        // Ordering means nothing to a count, and MySQL's strict grouping rules dislike it.
        $eligible = (clone $query)->reorder()->count();

        $queued = 0;
        if (! $dryRun) {
            // Longest-neglected records first; the cap protects the SMS budget.
            $query->limit($config['daily_limit'])->get()->each(function (AlumniProfile $profile) use (&$queued) {
                SendProfileNudge::dispatch($profile->id);
                $queued++;
            });
        } else {
            $queued = min($eligible, $config['daily_limit']);
        }

        return ['eligible' => $eligible, 'queued' => $queued];
    }

    /**
     * @return Builder<AlumniProfile>
     */
    public function candidates(CarbonInterface $now): Builder
    {
        $config = config('sunates.nudges');

        $statuses = [VerificationStatus::Verified];
        if ($config['include_unclaimed']) {
            $statuses[] = VerificationStatus::Unclaimed;
        }

        $sent = array_map(fn (NotificationStatus $s) => $s->value, NotificationStatus::delivered());
        $nudgeTemplates = array_map(fn (MessageTemplate $t) => $t->value, MessageTemplate::nudges());
        $placeholders = fn (array $values) => implode(',', array_fill(0, count($values), '?'));

        return AlumniProfile::query()
            ->whereIn('verification_status', $statuses)
            ->where(fn (Builder $q) => $q->whereNotNull('phone')->orWhereNotNull('whatsapp_number'))
            // Someone who has stopped both channels cannot be nudged at all.
            ->where(fn (Builder $q) => $q->whereNull('sms_opt_out_at')->orWhereNull('whatsapp_opt_out_at'))
            // Stale: no alumnus-made update (profile edit or survey answer) within the threshold.
            ->whereRaw(
                'GREATEST(COALESCE(profile_updated_at, created_at), COALESCE(last_survey_completed_at, created_at)) < ?',
                [$now->copy()->subDays($config['stale_after_days'])]
            )
            // Not messaged about anything recently (a survey invitation, say).
            ->whereDoesntHave('notificationLogs', fn (Builder $q) => $q
                ->whereIn('status', $sent)
                ->where('created_at', '>=', $now->copy()->subDays($config['cooldown_after_any_message_days'])))
            // Not nudged within the minimum gap...
            ->whereDoesntHave('notificationLogs', fn (Builder $q) => $q
                ->whereIn('template', $nudgeTemplates)
                ->whereIn('status', $sent)
                ->where('created_at', '>=', $now->copy()->subDays($config['min_gap_days'])))
            // ...and not more than max_per_year times in the last twelve months.
            ->whereRaw(
                "(SELECT COUNT(*) FROM notification_logs WHERE notification_logs.alumni_profile_id = alumni_profiles.id AND notification_logs.template IN ({$placeholders($nudgeTemplates)}) AND notification_logs.status IN ({$placeholders($sent)}) AND notification_logs.created_at >= ?) < ?",
                [...$nudgeTemplates, ...$sent, $now->copy()->subYear(), $config['max_per_year']]
            )
            ->orderByRaw('GREATEST(COALESCE(profile_updated_at, created_at), COALESCE(last_survey_completed_at, created_at))')
            ->orderBy('id');
    }
}

<?php

namespace App\Services\Surveys;

use App\Enums\SurveyInvitationStatus;
use App\Enums\VerificationStatus;
use App\Jobs\SendSurveyInvitation;
use App\Models\AlumniProfile;
use App\Models\SurveyInvitation;
use App\Models\TracerSurveyCycle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FR-3: decides who is due a 6-month, 1-year or 3-year survey, and keeps that machinery tidy.
 *
 * It is designed to run every day and be harmless when it does. There is one invitation per alumnus
 * per milestone, so re-running never sends twice, and only invitations whose window is still open
 * are created, so switching this on does not message everyone who graduated years ago.
 */
class SurveyScheduler
{
    /**
     * Create (and queue messages for) every invitation that has fallen due.
     *
     * @return array<int, int> milestone months => invitations created (or that would be, on a dry run)
     */
    public function schedule(?CarbonInterface $now = null, bool $dryRun = false): array
    {
        $now = Carbon::instance($now ?? now());
        $results = [];

        foreach (TracerSurveyCycle::query()->active()->orderBy('milestone_months')->get() as $cycle) {
            $today = $now->copy()->setTimezone(config('sunates.timezone'))->startOfDay();

            // Milestones from (today - window, today]: due, and not yet past their window.
            $from = $today->copy()->subDays($cycle->windowDays() - 1);
            $query = $this->profilesWithMilestoneBetween($cycle, $from, $today);

            $results[$cycle->milestone_months] = $dryRun
                ? $query->count()
                : $this->createInvitations($cycle, $query);
        }

        return $results;
    }

    /**
     * How many alumni will reach this milestone in the next few days (for the console's preview).
     */
    public function upcomingCount(TracerSurveyCycle $cycle, int $days = 30, ?CarbonInterface $now = null): int
    {
        $today = Carbon::instance($now ?? now())->setTimezone(config('sunates.timezone'))->startOfDay();

        return $this->profilesWithMilestoneBetween($cycle, $today->copy()->addDay(), $today->copy()->addDays($days))->count();
    }

    /** Invitations whose window has closed unanswered stop being answerable. */
    public function expireOverdue(?CarbonInterface $now = null): int
    {
        return SurveyInvitation::query()
            ->whereIn('status', [SurveyInvitationStatus::Scheduled, SurveyInvitationStatus::Sent])
            ->where('expires_at', '<=', $now ?? now())
            ->update(['status' => SurveyInvitationStatus::Expired]);
    }

    /**
     * Queue a reminder for everyone whose next reminder date has arrived (config: reminder_days,
     * counted from the first message).
     *
     * @return int reminders queued (or that would be, on a dry run)
     */
    public function sendReminders(?CarbonInterface $now = null, bool $dryRun = false): int
    {
        $now = Carbon::instance($now ?? now());
        $offsets = array_values(config('sunates.surveys.reminder_days'));
        $queued = 0;

        SurveyInvitation::query()
            ->where('status', SurveyInvitationStatus::Sent)
            ->where('expires_at', '>', $now)
            ->whereNotNull('sent_at')
            ->where('reminders_sent', '<', count($offsets))
            ->chunkById(200, function ($invitations) use ($offsets, $now, $dryRun, &$queued) {
                foreach ($invitations as $invitation) {
                    $dueAt = $invitation->sent_at->copy()->addDays($offsets[$invitation->reminders_sent]);

                    if ($dueAt->greaterThan($now)) {
                        continue;
                    }

                    $queued++;
                    if (! $dryRun) {
                        // The reminder's ordinal makes the job idempotent: a duplicate dispatch is a no-op.
                        SendSurveyInvitation::dispatch($invitation->id, $invitation->reminders_sent + 1);
                    }
                }
            });

        return $queued;
    }

    /**
     * Alumni eligible for a survey at this milestone whose milestone date falls in [from, to] and who
     * have not been invited to it yet.
     *
     * @return Builder<AlumniProfile>
     */
    private function profilesWithMilestoneBetween(TracerSurveyCycle $cycle, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $statuses = [VerificationStatus::Verified];
        if (config('sunates.surveys.include_unclaimed')) {
            $statuses[] = VerificationStatus::Unclaimed;
        }

        return AlumniProfile::query()
            ->whereIn('verification_status', $statuses)
            // Graduation date if the Registrar has one, otherwise the end of the graduation year.
            // MySQL date arithmetic (spec section 8): DATE_ADD clamps to month-end just as addMonthsNoOverflow does.
            ->whereRaw(
                "DATE_ADD(COALESCE(graduation_date, STR_TO_DATE(CONCAT(graduation_year, '-', ?), '%Y-%m-%d')), INTERVAL ? MONTH) BETWEEN ? AND ?",
                [config('sunates.surveys.fallback_graduation_month_day'), $cycle->milestone_months, $from->toDateString(), $to->toDateString()]
            )
            // Reachable somehow: a phone to message, or an account to see the survey in the app.
            ->where(fn (Builder $q) => $q->whereNotNull('user_id')->orWhereNotNull('phone')->orWhereNotNull('whatsapp_number'))
            ->whereDoesntHave('surveyInvitations', fn (Builder $q) => $q->where('tracer_survey_cycle_id', $cycle->id));
    }

    /**
     * @param  Builder<AlumniProfile>  $query
     */
    private function createInvitations(TracerSurveyCycle $cycle, Builder $query): int
    {
        $created = 0;
        $tz = config('sunates.timezone');

        $query->chunkById(200, function ($profiles) use ($cycle, $tz, &$created) {
            foreach ($profiles as $profile) {
                $milestone = $profile->milestoneBaseDate()->addMonthsNoOverflow($cycle->milestone_months);
                $dueAt = Carbon::parse($milestone->toDateString(), $tz)->utc();

                // firstOrCreate + the unique index make a concurrent or repeated run safe.
                $invitation = SurveyInvitation::query()->firstOrCreate(
                    ['tracer_survey_cycle_id' => $cycle->id, 'alumni_profile_id' => $profile->id],
                    [
                        'token' => Str::random(40),
                        'status' => SurveyInvitationStatus::Scheduled,
                        'due_at' => $dueAt,
                        'expires_at' => $dueAt->copy()->addDays($cycle->windowDays()),
                    ]
                );

                if ($invitation->wasRecentlyCreated) {
                    $created++;
                    SendSurveyInvitation::dispatch($invitation->id);
                }
            }
        });

        return $created;
    }
}

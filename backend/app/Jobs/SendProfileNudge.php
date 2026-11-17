<?php

namespace App\Jobs;

use App\Enums\MessageTemplate;
use App\Enums\NotificationStatus;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Services\Messaging\NotificationService;
use App\Services\Nudges\ProfileNudger;
use App\Support\QuietHours;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One "please update your details" message. Registered alumni are asked to confirm their record;
 * imported alumni who have not registered are invited to join (only when config allows that).
 */
class SendProfileNudge implements ShouldQueue
{
    use Queueable;

    /**
     * Genuine failures (provider trouble) allowed before giving up. Waiting out quiet hours is a
     * release, not a failure, so it never uses these up; retryUntil() bounds the total wait instead.
     */
    public int $maxExceptions = 6;

    public function __construct(public readonly int $profileId) {}

    /** Keep trying (through quiet hours and back-off) for three days, then give up. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(3);
    }

    /** @return list<int> seconds */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle(NotificationService $notifications, ProfileNudger $nudger): void
    {
        if (QuietHours::isQuiet()) {
            $this->release(QuietHours::secondsUntilOpen());

            return;
        }

        $profile = AlumniProfile::query()->find($this->profileId);
        if (! $profile) {
            return;
        }

        // The job may have waited hours (quiet hours, back-off) since it was queued: re-apply every
        // rule now. If the alumnus updated their record, opted out, heard from us about something else,
        // or was nudged by another run in the meantime, they are no longer a candidate.
        if (! $nudger->candidates(now())->whereKey($profile->id)->exists()) {
            return;
        }

        // A duplicate job running at the same moment as this one: its attempt is logged as queued (in
        // flight) or sent. A failed attempt does not count, so a retry after provider trouble does send.
        $inFlight = NotificationLog::query()
            ->where('alumni_profile_id', $profile->id)
            ->whereIn('template', array_map(fn (MessageTemplate $t) => $t->value, MessageTemplate::nudges()))
            ->whereIn('status', array_map(fn (NotificationStatus $s) => $s->value, [NotificationStatus::Queued, ...NotificationStatus::delivered()]))
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($inFlight) {
            return;
        }

        $registered = $profile->user_id !== null;

        $notifications->send(
            $profile,
            $registered ? MessageTemplate::ProfileNudge : MessageTemplate::RegisterInvite,
            ['url' => config('sunates.pwa_url').($registered ? '' : '/register')],
        );
    }
}

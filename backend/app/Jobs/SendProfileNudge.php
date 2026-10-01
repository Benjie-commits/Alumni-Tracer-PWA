<?php

namespace App\Jobs;

use App\Enums\MessageTemplate;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Services\Messaging\NotificationService;
use App\Support\QuietHours;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One "please update your details" message. Registered alumni are asked to confirm their record;
 * imported alumni who have not registered are invited to join (only when config allows that).
 */
class SendProfileNudge implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public readonly int $profileId) {}

    /** @return list<int> seconds */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle(NotificationService $notifications): void
    {
        if (QuietHours::isQuiet()) {
            $this->release(QuietHours::secondsUntilOpen());

            return;
        }

        $profile = AlumniProfile::query()->find($this->profileId);
        if (! $profile) {
            return;
        }

        // A retry after a crash, or a run that overlapped another, must not double-nudge.
        $alreadyToday = NotificationLog::query()
            ->where('alumni_profile_id', $profile->id)
            ->whereIn('template', array_map(fn (MessageTemplate $t) => $t->value, MessageTemplate::nudges()))
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($alreadyToday) {
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

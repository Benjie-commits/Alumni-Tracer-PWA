<?php

namespace App\Jobs;

use App\Enums\MessageTemplate;
use App\Enums\NotificationStatus;
use App\Enums\SurveyInvitationStatus;
use App\Models\SurveyInvitation;
use App\Services\Messaging\NotificationService;
use App\Support\QuietHours;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a survey's first message (reminderNumber 0) or one of its reminders (1, 2, ...).
 *
 * Safe to run more than once: it checks what has already gone out, so a duplicate dispatch or a
 * retry after a crash never sends the same message twice.
 */
class SendSurveyInvitation implements ShouldQueue
{
    use Queueable;

    /** Generous, because most failures are temporary provider trouble and back-off spreads them out. */
    public int $tries = 10;

    public function __construct(
        public readonly int $invitationId,
        public readonly int $reminderNumber = 0,
    ) {}

    /** @return list<int> seconds */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 14400];
    }

    public function handle(NotificationService $notifications): void
    {
        // Nobody wants a survey SMS at 2am: wait for the morning.
        if (QuietHours::isQuiet()) {
            $this->release(QuietHours::secondsUntilOpen());

            return;
        }

        $invitation = SurveyInvitation::query()->with(['profile', 'cycle'])->find($this->invitationId);

        if (! $invitation || ! $invitation->isOpen() || $this->alreadyDone($invitation)) {
            return;
        }

        $isReminder = $this->reminderNumber > 0;

        $log = $notifications->send(
            $invitation->profile,
            $isReminder ? MessageTemplate::SurveyReminder : MessageTemplate::SurveyInvite,
            ['url' => $invitation->url(), 'period' => $invitation->cycle->periodLabel()],
            $invitation,
        );

        // Blocked or failed (no number, opted out, every channel refused): the survey stays open in
        // the app and the failure is visible in the notification log; it is not retried automatically.
        if (! in_array($log->status, NotificationStatus::delivered(), true)) {
            return;
        }

        if ($isReminder) {
            $invitation->update(['reminders_sent' => $this->reminderNumber, 'last_reminded_at' => now()]);
        } else {
            $invitation->update(['status' => SurveyInvitationStatus::Sent, 'sent_at' => now()]);
        }
    }

    private function alreadyDone(SurveyInvitation $invitation): bool
    {
        return $this->reminderNumber > 0
            ? $invitation->reminders_sent >= $this->reminderNumber
            : $invitation->sent_at !== null;
    }
}

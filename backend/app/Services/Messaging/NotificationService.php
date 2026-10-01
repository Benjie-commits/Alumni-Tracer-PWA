<?php

namespace App\Services\Messaging;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Contracts\WhatsAppGateway;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The one place messages leave the system (spec section 5.3: notification engine).
 *
 * For each message it works out which channels this person can actually be reached on, respecting
 * their opt-outs, tries them in the configured order (WhatsApp, then SMS), and records every
 * attempt in the notification log.
 */
class NotificationService
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly WhatsAppGateway $whatsapp,
    ) {}

    /**
     * @param  array<string, string>  $params  template values such as url and period; first_name and stop_url are added
     * @return NotificationLog the log of the attempt that succeeded, or of the last one that failed / was blocked
     *
     * @throws TransientGatewayException when a provider had a temporary problem (the queued job retries)
     */
    public function send(AlumniProfile $profile, MessageTemplate $template, array $params = [], ?Model $related = null): NotificationLog
    {
        $params += [
            'first_name' => $profile->first_name,
            'stop_url' => $this->stopUrl($profile),
        ];

        $targets = $this->targets($profile);

        if ($targets === []) {
            return $this->blocked($profile, $template, $related, $this->whyUnreachable($profile));
        }

        $log = null;

        foreach ($targets as [$channel, $number]) {
            $log = NotificationLog::query()->create([
                'alumni_profile_id' => $profile->id,
                'channel' => $channel,
                'template' => $template,
                'to_number' => $number,
                'status' => NotificationStatus::Queued,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]);

            try {
                $result = $channel === NotificationChannel::Whatsapp
                    ? $this->whatsapp->sendTemplate($number, $template->whatsappTemplateName(), (string) config('sunates.messaging.whatsapp_language'), $template->whatsappParams($params))
                    : $this->sms->send($number, $template->sms($params));
            } catch (PermanentGatewayException $e) {
                $log->update(['status' => NotificationStatus::Failed, 'failed_at' => now(), 'error' => $e->getMessage()]);

                continue; // try the next channel
            } catch (TransientGatewayException $e) {
                $log->update(['status' => NotificationStatus::Failed, 'failed_at' => now(), 'error' => 'Temporary problem, will retry: '.$e->getMessage()]);

                throw $e;
            }

            $log->update([
                'status' => NotificationStatus::Sent,
                'provider' => $result->provider,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
            ]);

            return $log;
        }

        return $log;
    }

    /**
     * A one-off message to a number typed in by ICT, to prove the provider credentials work before
     * go-live. Not tied to any alumnus, and unlike send() it never throws: the outcome is the log.
     */
    public function sendTest(NotificationChannel $channel, string $toE164): NotificationLog
    {
        $template = MessageTemplate::Test;

        $log = NotificationLog::query()->create([
            'alumni_profile_id' => null,
            'channel' => $channel,
            'template' => $template,
            'to_number' => $toE164,
            'status' => NotificationStatus::Queued,
        ]);

        try {
            $result = $channel === NotificationChannel::Whatsapp
                ? $this->whatsapp->sendTemplate($toE164, $template->whatsappTemplateName(), (string) config('sunates.messaging.whatsapp_language'), [])
                : $this->sms->send($toE164, $template->sms([]));
        } catch (PermanentGatewayException|TransientGatewayException $e) {
            $log->update(['status' => NotificationStatus::Failed, 'failed_at' => now(), 'error' => $e->getMessage()]);

            return $log;
        }

        $log->update([
            'status' => NotificationStatus::Sent,
            'provider' => $result->provider,
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => now(),
        ]);

        return $log;
    }

    /**
     * Channels this person can be reached on, best first, each with a valid E.164 number.
     *
     * @return list<array{0: NotificationChannel, 1: string}>
     */
    public function targets(AlumniProfile $profile): array
    {
        $targets = [];

        foreach (config('sunates.messaging.channel_priority') as $name) {
            $channel = NotificationChannel::from($name);

            if ($profile->hasOptedOut($channel)) {
                continue;
            }

            // A WhatsApp number falls back to the main phone (most people use one number for both).
            $raw = $channel === NotificationChannel::Whatsapp
                ? ($profile->whatsapp_number ?: $profile->phone)
                : ($profile->phone ?: $profile->whatsapp_number);

            if ($number = PhoneNumber::toE164($raw)) {
                $targets[] = [$channel, $number];
            }
        }

        return $targets;
    }

    /** "Stop messaging me" link that works from any SMS without signing in. */
    public function stopUrl(AlumniProfile $profile): string
    {
        if ($profile->unsubscribe_token === null) {
            $profile->forceFill(['unsubscribe_token' => Str::random(24)])->save();
        }

        return rtrim((string) config('app.url'), '/').'/u/'.$profile->unsubscribe_token;
    }

    private function whyUnreachable(AlumniProfile $profile): string
    {
        $hasNumber = PhoneNumber::toE164($profile->phone) !== null || PhoneNumber::toE164($profile->whatsapp_number) !== null;

        return $hasNumber ? 'opted_out' : 'no_valid_number';
    }

    private function blocked(AlumniProfile $profile, MessageTemplate $template, ?Model $related, string $reason): NotificationLog
    {
        return NotificationLog::query()->create([
            'alumni_profile_id' => $profile->id,
            'channel' => null,
            'template' => $template,
            'status' => NotificationStatus::Blocked,
            'error' => $reason,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
        ]);
    }
}

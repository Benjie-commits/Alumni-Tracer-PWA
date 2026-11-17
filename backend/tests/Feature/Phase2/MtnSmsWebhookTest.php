<?php

namespace Tests\Feature\Phase2;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;

/** The callback URL given to MTN: delivery receipts and STOP replies, guarded by a secret in the path. */
class MtnSmsWebhookTest extends Phase2TestCase
{
    private const SECRET = 'a-long-secret-in-the-callback-url';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mtn_sms.callback_secret' => self::SECRET]);
    }

    private function hook(array $payload, string $secret = self::SECRET)
    {
        return $this->postJson("/api/webhooks/mtn-sms/{$secret}", $payload);
    }

    private function smsLog(string $providerId = 'mtn-1', NotificationStatus $status = NotificationStatus::Sent, array $profile = []): NotificationLog
    {
        return NotificationLog::create([
            'alumni_profile_id' => $this->graduate($profile)->id,
            'channel' => NotificationChannel::Sms,
            'template' => MessageTemplate::SurveyInvite,
            'status' => $status,
            'provider' => 'mtn',
            'provider_message_id' => $providerId,
            'sent_at' => now(),
        ]);
    }

    public function test_the_endpoint_does_not_exist_until_a_secret_is_set_and_a_wrong_secret_is_not_found(): void
    {
        $this->hook(['messageId' => 'mtn-1', 'deliveryStatus' => 'DELIVERED'], 'wrong')->assertNotFound();

        config(['services.mtn_sms.callback_secret' => '']);
        $this->hook(['messageId' => 'mtn-1', 'deliveryStatus' => 'DELIVERED'], '')->assertNotFound();
        $this->hook(['messageId' => 'mtn-1', 'deliveryStatus' => 'DELIVERED'], 'anything')->assertNotFound();
    }

    public function test_a_delivery_receipt_marks_the_message_delivered(): void
    {
        $log = $this->smsLog();

        $this->hook(['messageId' => 'mtn-1', 'deliveryStatus' => 'DELIVERED'])->assertOk();

        $log->refresh();
        $this->assertSame(NotificationStatus::Delivered, $log->status);
        $this->assertNotNull($log->delivered_at);
    }

    public function test_receipts_in_a_list_or_an_envelope_are_understood(): void
    {
        $a = $this->smsLog('a');
        $b = $this->smsLog('b');

        $this->hook([['messageId' => 'a', 'deliveryStatus' => 'DELIVERED']])->assertOk();
        $this->hook(['deliveryInfoNotification' => ['deliveryInfo' => ['messageId' => 'b', 'deliveryStatus' => 'UNDELIVERABLE']]])->assertOk();

        $this->assertSame(NotificationStatus::Delivered, $a->refresh()->status);
        $this->assertSame(NotificationStatus::Failed, $b->refresh()->status);
    }

    public function test_a_late_receipt_never_undoes_a_delivery(): void
    {
        $log = $this->smsLog('mtn-1', NotificationStatus::Delivered);

        $this->hook(['messageId' => 'mtn-1', 'deliveryStatus' => 'SENT'])->assertOk();
        $this->hook(['messageId' => 'mtn-1', 'deliveryStatus' => 'UNDELIVERABLE'])->assertOk();

        $this->assertSame(NotificationStatus::Delivered, $log->refresh()->status);
    }

    public function test_a_stop_reply_opts_the_alumnus_out_everywhere(): void
    {
        $log = $this->smsLog('mtn-1', NotificationStatus::Sent, ['phone' => '+256700123456']);

        $this->hook(['senderAddress' => '256700123456', 'message' => ' Stop. '])->assertOk();

        $profile = $log->profile->refresh();
        $this->assertNotNull($profile->sms_opt_out_at);
        $this->assertNotNull($profile->whatsapp_opt_out_at);
    }

    public function test_other_replies_and_odd_payloads_change_nothing_and_do_not_error(): void
    {
        $log = $this->smsLog('mtn-1', NotificationStatus::Sent, ['phone' => '+256700123456']);

        $this->hook(['senderAddress' => '256700123456', 'message' => 'thanks!'])->assertOk();
        foreach ([[], ['notifications' => 'nonsense'], [['x' => 1]], ['messageId' => 'unknown', 'deliveryStatus' => 'DELIVERED']] as $payload) {
            $this->hook($payload)->assertOk();
        }

        $this->assertNull($log->profile->refresh()->sms_opt_out_at);
        $this->assertSame(NotificationStatus::Sent, $log->refresh()->status);
    }
}

<?php

namespace Tests\Feature\Phase2;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Services\Messaging\NotificationService;

class WebhookAndUnsubscribeTest extends Phase2TestCase
{
    private const SECRET = 'meta-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.app_secret' => self::SECRET, 'services.whatsapp.verify_token' => 'my-verify-token']);
    }

    /** POST a webhook body signed the way Meta signs it. */
    private function webhook(array $payload, ?string $signature = null, ?string $secret = self::SECRET)
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $signature ??= 'sha256='.hash_hmac('sha256', $body, (string) $secret);
        if ($signature !== '') {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], $headers, $body);
    }

    /** @return array<string, mixed> */
    private function statusPayload(string $messageId, string $status, array $extra = []): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'statuses' => [['id' => $messageId, 'status' => $status, 'recipient_id' => '256700111222'] + $extra],
        ]]]]]];
    }

    /** @return array<string, mixed> */
    private function replyPayload(string $from, string $text, string $type = 'text'): array
    {
        return ['entry' => [['changes' => [['value' => [
            'messages' => [['from' => $from, 'id' => 'wamid.in1', 'type' => $type, 'text' => ['body' => $text]]],
        ]]]]]];
    }

    private function sentLog(string $providerId = 'wamid.out1', NotificationStatus $status = NotificationStatus::Sent): NotificationLog
    {
        return NotificationLog::create([
            'alumni_profile_id' => $this->graduate()->id,
            'channel' => NotificationChannel::Whatsapp,
            'template' => MessageTemplate::SurveyInvite,
            'status' => $status,
            'provider' => 'whatsapp',
            'provider_message_id' => $providerId,
            'sent_at' => now(),
        ]);
    }

    // ---- registering the webhook -------------------------------------------------------

    public function test_meta_can_verify_the_webhook_url(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=my-verify-token&hub.challenge=987654')
            ->assertOk()
            ->assertSee('987654', false);
    }

    public function test_a_wrong_or_missing_verify_token_is_refused(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=guess&hub.challenge=1')->assertForbidden();
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=1')->assertForbidden();
        $this->get('/api/webhooks/whatsapp?hub.mode=unsubscribe&hub.verify_token=my-verify-token&hub.challenge=1')->assertForbidden();
    }

    public function test_verification_is_refused_when_no_token_is_configured(): void
    {
        config(['services.whatsapp.verify_token' => null]);

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
    }

    // ---- signatures --------------------------------------------------------------------

    public function test_unsigned_or_wrongly_signed_posts_are_refused_and_change_nothing(): void
    {
        $log = $this->sentLog();

        $this->webhook($this->statusPayload('wamid.out1', 'delivered'), signature: '')->assertForbidden();
        $this->webhook($this->statusPayload('wamid.out1', 'delivered'), signature: 'sha256=deadbeef')->assertForbidden();
        $this->webhook($this->statusPayload('wamid.out1', 'delivered'), secret: 'someone-elses-secret')->assertForbidden();
        $this->webhook($this->statusPayload('wamid.out1', 'delivered'), signature: 'md5=abc')->assertForbidden();

        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
    }

    public function test_with_no_app_secret_configured_everything_is_refused(): void
    {
        config(['services.whatsapp.app_secret' => null]);
        $log = $this->sentLog();

        $this->webhook($this->statusPayload('wamid.out1', 'delivered'), secret: '')->assertForbidden();

        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
    }

    public function test_tampering_with_a_signed_body_is_detected(): void
    {
        $log = $this->sentLog();
        $signature = 'sha256='.hash_hmac('sha256', json_encode($this->statusPayload('wamid.out1', 'read')), self::SECRET);

        $this->webhook($this->statusPayload('wamid.out1', 'failed'), signature: $signature)->assertForbidden();

        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
    }

    // ---- delivery receipts -------------------------------------------------------------

    public function test_a_delivery_receipt_updates_the_log(): void
    {
        $log = $this->sentLog();

        $this->webhook($this->statusPayload('wamid.out1', 'delivered'))->assertOk();

        $log->refresh();
        $this->assertSame(NotificationStatus::Delivered, $log->status);
        $this->assertNotNull($log->delivered_at);
    }

    public function test_a_read_receipt_moves_it_on_again(): void
    {
        $log = $this->sentLog();

        $this->webhook($this->statusPayload('wamid.out1', 'delivered'));
        $this->webhook($this->statusPayload('wamid.out1', 'read'));

        $this->assertSame(NotificationStatus::Read, $log->fresh()->status);
    }

    public function test_receipts_arriving_out_of_order_never_move_a_message_backwards(): void
    {
        $log = $this->sentLog(status: NotificationStatus::Read);

        $this->webhook($this->statusPayload('wamid.out1', 'delivered'));
        $this->webhook($this->statusPayload('wamid.out1', 'sent'));

        $this->assertSame(NotificationStatus::Read, $log->fresh()->status);
    }

    public function test_a_failure_before_delivery_is_recorded_with_the_reason(): void
    {
        $log = $this->sentLog();

        $this->webhook($this->statusPayload('wamid.out1', 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]))->assertOk();

        $log->refresh();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertNotNull($log->failed_at);
        $this->assertSame('Message undeliverable (error 131026)', $log->error);
    }

    public function test_a_stale_failure_after_delivery_is_ignored(): void
    {
        $log = $this->sentLog(status: NotificationStatus::Delivered);

        $this->webhook($this->statusPayload('wamid.out1', 'failed'));

        $this->assertSame(NotificationStatus::Delivered, $log->fresh()->status);
    }

    public function test_receipts_for_messages_we_do_not_know_are_ignored_politely(): void
    {
        $this->webhook($this->statusPayload('wamid.unknown', 'delivered'))->assertOk();
        $this->webhook($this->statusPayload('wamid.out1', 'something_new'))->assertOk();

        $this->assertSame(0, NotificationLog::count());
    }

    public function test_odd_but_signed_payloads_do_not_cause_errors_or_retries(): void
    {
        foreach ([[], ['entry' => []], ['entry' => [['changes' => []]]], ['entry' => [['changes' => [['value' => []]]]]], ['entry' => 'nonsense']] as $payload) {
            $this->webhook($payload)->assertOk();
        }
    }

    // ---- replies -----------------------------------------------------------------------

    public function test_replying_stop_ends_messages_on_every_channel_however_the_number_was_typed(): void
    {
        $typedOddly = $this->graduate(['phone' => '0700 111-222', 'whatsapp_number' => null]);
        $someoneElse = $this->graduate(['phone' => '0755 999 000']);

        $this->webhook($this->replyPayload('256700111222', 'STOP'))->assertOk();

        $typedOddly->refresh();
        $this->assertNotNull($typedOddly->sms_opt_out_at);
        $this->assertNotNull($typedOddly->whatsapp_opt_out_at);
        $this->assertNull($someoneElse->fresh()->sms_opt_out_at);
    }

    public function test_stop_is_recognised_in_any_case_and_with_punctuation(): void
    {
        foreach (['stop', 'Stop.', '  UNSUBSCRIBE!  ', 'Opt out', 'cancel'] as $text) {
            $profile = $this->graduate(['phone' => '0700 111 222']);

            $this->webhook($this->replyPayload('256700111222', $text));

            $this->assertNotNull($profile->fresh()->whatsapp_opt_out_at, "'{$text}' should be treated as a stop request");
            $profile->forceDelete();
        }
    }

    public function test_ordinary_replies_and_non_text_messages_do_not_opt_anyone_out(): void
    {
        $profile = $this->graduate(['phone' => '0700 111 222']);

        $this->webhook($this->replyPayload('256700111222', 'Thanks, will do!'));
        $this->webhook($this->replyPayload('256700111222', 'please do not stop the survey reminders being useful'));
        $this->webhook($this->replyPayload('256700111222', 'STOP', type: 'image'));

        $this->assertNull($profile->fresh()->whatsapp_opt_out_at);
    }

    public function test_stop_from_an_unknown_number_changes_nobody(): void
    {
        $profile = $this->graduate(['phone' => '0700 111 222']);

        $this->webhook($this->replyPayload('256788000000', 'STOP'))->assertOk();

        $this->assertNull($profile->fresh()->sms_opt_out_at);
    }

    public function test_stop_covers_everyone_who_shares_the_number(): void
    {
        $a = $this->graduate(['phone' => '0700 111 222']);
        $b = $this->graduate(['phone' => null, 'whatsapp_number' => '+256700111222']);

        $this->webhook($this->replyPayload('256700111222', 'stop'));

        $this->assertNotNull($a->fresh()->sms_opt_out_at);
        $this->assertNotNull($b->fresh()->sms_opt_out_at);
    }

    public function test_the_first_opt_out_time_is_kept_when_stop_is_sent_twice(): void
    {
        $profile = $this->graduate(['phone' => '0700 111 222']);
        $this->webhook($this->replyPayload('256700111222', 'stop'));
        $first = $profile->fresh()->sms_opt_out_at;

        $this->at('2026-10-06 10:00');
        $this->webhook($this->replyPayload('256700111222', 'stop'));

        $this->assertEquals($first, $profile->fresh()->sms_opt_out_at);
    }

    // ---- the unsubscribe page ----------------------------------------------------------

    public function test_opening_the_stop_link_changes_nothing_until_the_button_is_pressed(): void
    {
        $profile = $this->graduate(['first_name' => 'Amina']);

        // Messaging apps pre-fetch links to check them; that must not unsubscribe anyone.
        $this->get('/u/'.$profile->unsubscribe_token)
            ->assertOk()
            ->assertSee('Amina')
            ->assertSee('Yes, stop messages');

        $this->assertNull($profile->fresh()->sms_opt_out_at);
        $this->assertNull($profile->fresh()->whatsapp_opt_out_at);
    }

    public function test_pressing_the_button_stops_sms_and_whatsapp(): void
    {
        $profile = $this->graduate();

        $this->post('/u/'.$profile->unsubscribe_token)
            ->assertOk()
            ->assertSee("You won't get any more messages", false);

        $profile->refresh();
        $this->assertNotNull($profile->sms_opt_out_at);
        $this->assertNotNull($profile->whatsapp_opt_out_at);
        $this->assertNull($profile->deleted_at, 'their record is untouched');
    }

    public function test_after_stopping_nothing_more_is_sent_to_them(): void
    {
        $profile = $this->graduate();
        $this->post('/u/'.$profile->unsubscribe_token);

        $log = app(NotificationService::class)->send($profile->fresh(), MessageTemplate::SurveyInvite, ['url' => 'x', 'period' => '6 months']);

        $this->assertSame(NotificationStatus::Blocked, $log->status);
        $this->assertSame([], $this->sms->sent);
        $this->assertSame([], $this->whatsapp->sent);
    }

    public function test_an_unknown_stop_link_is_not_found(): void
    {
        $this->get('/u/'.str_repeat('z', 24))->assertNotFound();
        $this->post('/u/'.str_repeat('z', 24))->assertNotFound();
    }

    public function test_the_stop_page_is_not_indexed_and_needs_no_sign_in(): void
    {
        $profile = $this->graduate();

        $this->get('/u/'.$profile->unsubscribe_token)->assertOk()->assertSee('noindex', false);
    }

    public function test_every_profile_gets_its_own_unguessable_token(): void
    {
        $a = $this->graduate();
        $b = $this->graduate();

        $this->assertSame(24, strlen($a->unsubscribe_token));
        $this->assertNotSame($a->unsubscribe_token, $b->unsubscribe_token);
    }
}

<?php

namespace Tests\Feature\Phase2;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Models\SurveyInvitation;
use App\Models\TracerSurveyCycle;
use App\Services\Messaging\NotificationService;
use App\Services\Messaging\PermanentGatewayException;
use App\Services\Messaging\TransientGatewayException;
use Illuminate\Support\Facades\DB;

class NotificationServiceTest extends Phase2TestCase
{
    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    /** @return array<string, string> */
    private function params(): array
    {
        return ['url' => 'https://alumni.example/s/abc', 'period' => '6 months'];
    }

    public function test_whatsapp_is_tried_first_and_the_attempt_is_logged(): void
    {
        $profile = $this->graduate(['phone' => '0700 111 222', 'first_name' => 'Amina']);

        $log = $this->service()->send($profile, MessageTemplate::SurveyInvite, $this->params());

        $this->assertSame(NotificationChannel::Whatsapp, $log->channel);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame('+256700111222', $log->to_number);
        $this->assertSame('fake-whatsapp', $log->provider);
        $this->assertSame('wamid.1', $log->provider_message_id);
        $this->assertNotNull($log->sent_at);
        $this->assertSame([], $this->sms->sent);
        $this->assertSame(
            ['to' => '+256700111222', 'template' => 'sunates_survey_invite', 'language' => 'en', 'params' => ['Amina', '6 months', 'https://alumni.example/s/abc']],
            $this->whatsapp->sent[0]
        );
    }

    public function test_it_falls_back_to_sms_when_whatsapp_refuses_permanently(): void
    {
        $this->whatsapp->failWith = new PermanentGatewayException('recipient is not on WhatsApp');
        $profile = $this->graduate();

        $log = $this->service()->send($profile, MessageTemplate::SurveyInvite, $this->params());

        $this->assertSame(NotificationChannel::Sms, $log->channel);
        $this->assertSame(NotificationStatus::Sent, $log->status);

        $attempts = NotificationLog::orderBy('id')->get();
        $this->assertCount(2, $attempts, 'both attempts are on record');
        $this->assertSame(NotificationStatus::Failed, $attempts[0]->status);
        $this->assertSame('recipient is not on WhatsApp', $attempts[0]->error);
        $this->assertNotNull($attempts[0]->failed_at);
    }

    public function test_a_temporary_problem_is_retried_rather_than_falling_back_to_avoid_double_messaging(): void
    {
        $this->whatsapp->failWith = new TransientGatewayException('provider is down');
        $profile = $this->graduate();

        try {
            $this->service()->send($profile, MessageTemplate::SurveyInvite, $this->params());
            $this->fail('Expected the temporary failure to propagate so the queued job retries.');
        } catch (TransientGatewayException) {
            // expected
        }

        $this->assertSame([], $this->sms->sent, 'must not also text them while WhatsApp may yet succeed');
        $log = NotificationLog::sole();
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertStringContainsString('will retry', $log->error);
    }

    public function test_when_every_channel_refuses_the_last_failure_is_returned_without_an_exception(): void
    {
        $this->whatsapp->failWith = new PermanentGatewayException('nope');
        $this->sms->failWith = new PermanentGatewayException('also nope');

        $log = $this->service()->send($this->graduate(), MessageTemplate::SurveyInvite, $this->params());

        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertSame('also nope', $log->error);
        $this->assertSame(2, NotificationLog::count());
    }

    public function test_someone_who_left_whatsapp_is_reached_by_sms_and_vice_versa(): void
    {
        $noWhatsapp = $this->graduate();
        $noWhatsapp->setOptOut(NotificationChannel::Whatsapp, true);
        $noWhatsapp->save();

        $log = $this->service()->send($noWhatsapp, MessageTemplate::SurveyInvite, $this->params());
        $this->assertSame(NotificationChannel::Sms, $log->channel);
        $this->assertSame([], $this->whatsapp->sent);

        $noSms = $this->graduate(['phone' => '0700 333 444']);
        $noSms->setOptOut(NotificationChannel::Sms, true);
        $noSms->save();
        $this->whatsapp->failWith = new PermanentGatewayException('nope');

        $log = $this->service()->send($noSms, MessageTemplate::SurveyInvite, $this->params());
        $this->assertSame(NotificationStatus::Failed, $log->status, 'WhatsApp failed and SMS is switched off, so nothing else is tried');
        $this->assertCount(1, $this->sms->sent, 'only the first alumnus was texted');
    }

    public function test_someone_who_opted_out_of_everything_gets_nothing_and_the_reason_is_recorded(): void
    {
        $profile = $this->graduate();
        $profile->setOptOut(null, true);
        $profile->save();

        $log = $this->service()->send($profile, MessageTemplate::SurveyInvite, $this->params());

        $this->assertSame(NotificationStatus::Blocked, $log->status);
        $this->assertNull($log->channel);
        $this->assertSame('opted_out', $log->error);
        $this->assertSame([], $this->whatsapp->sent);
        $this->assertSame([], $this->sms->sent);
    }

    public function test_someone_with_no_usable_number_is_blocked_with_that_reason(): void
    {
        foreach ([['phone' => null, 'whatsapp_number' => null], ['phone' => 'call me', 'whatsapp_number' => '123']] as $numbers) {
            $log = $this->service()->send($this->graduate($numbers), MessageTemplate::SurveyInvite, $this->params());

            $this->assertSame(NotificationStatus::Blocked, $log->status);
            $this->assertSame('no_valid_number', $log->error);
        }
    }

    public function test_each_channel_uses_the_right_number(): void
    {
        $profile = $this->graduate(['phone' => '0700 111 222', 'whatsapp_number' => '0777 333 444']);
        $this->whatsapp->failWith = new PermanentGatewayException('nope');

        $this->service()->send($profile, MessageTemplate::SurveyInvite, $this->params());

        $this->assertSame('+256777333444', NotificationLog::orderBy('id')->first()->to_number, 'WhatsApp goes to the WhatsApp number');
        $this->assertSame('+256700111222', $this->sms->sent[0]['to'], 'SMS goes to the main phone');
    }

    public function test_a_missing_whatsapp_number_falls_back_to_the_main_phone_and_the_reverse(): void
    {
        $onlyWhatsapp = $this->graduate(['phone' => null, 'whatsapp_number' => '0777 333 444']);
        $this->smsOnly();

        $this->service()->send($onlyWhatsapp, MessageTemplate::SurveyInvite, $this->params());

        $this->assertSame('+256777333444', $this->sms->sent[0]['to']);
    }

    public function test_configured_channel_order_is_respected(): void
    {
        config(['sunates.messaging.channel_priority' => ['sms', 'whatsapp']]);

        $log = $this->service()->send($this->graduate(), MessageTemplate::SurveyInvite, $this->params());

        $this->assertSame(NotificationChannel::Sms, $log->channel);
    }

    public function test_a_stop_link_is_always_included_and_created_if_missing(): void
    {
        $this->smsOnly();
        $profile = $this->graduate();
        AlumniProfileTokenWipe::of($profile);

        $this->service()->send($profile->fresh(), MessageTemplate::SurveyInvite, $this->params());

        $token = $profile->fresh()->unsubscribe_token;
        $this->assertNotNull($token);
        $this->assertStringContainsString("/u/{$token}", $this->sms->sent[0]['message']);
    }

    public function test_the_related_record_is_linked_to_the_log(): void
    {
        $profile = $this->graduate();
        $invitation = SurveyInvitation::create([
            'tracer_survey_cycle_id' => TracerSurveyCycle::first()->id,
            'alumni_profile_id' => $profile->id,
            'token' => str_repeat('a', 40),
            'due_at' => now(), 'expires_at' => now()->addDays(90),
        ]);

        $log = $this->service()->send($profile, MessageTemplate::SurveyInvite, $this->params(), $invitation);

        $this->assertTrue($log->related->is($invitation));
    }

    public function test_every_template_renders_for_both_channels(): void
    {
        $p = ['first_name' => 'Amina', 'url' => 'https://a.example/x', 'stop_url' => 'https://a.example/u/1', 'period' => '1 year'];

        foreach (MessageTemplate::cases() as $template) {
            $sms = $template->sms($p);
            $this->assertNotSame('', $sms);
            $this->assertIsArray($template->whatsappParams($p));
            $this->assertNotSame('', $template->whatsappTemplateName(), "{$template->value} needs a WhatsApp template name in config");

            if ($template !== MessageTemplate::Test) {
                $this->assertStringContainsString('https://a.example/x', $sms);
                $this->assertStringContainsString('https://a.example/u/1', $sms, 'every SMS must tell people how to stop');
            }
        }
    }

    public function test_sms_messages_stay_short_enough_to_be_cheap(): void
    {
        $p = ['first_name' => 'Amina', 'url' => 'https://alumni.soroti.ac.ug/s/'.str_repeat('x', 40), 'stop_url' => 'https://alumni.soroti.ac.ug/u/'.str_repeat('y', 24), 'period' => '3 years'];

        foreach ([MessageTemplate::SurveyInvite, MessageTemplate::SurveyReminder, MessageTemplate::ProfileNudge, MessageTemplate::RegisterInvite] as $template) {
            $this->assertLessThanOrEqual(306, mb_strlen($template->sms($p)), "{$template->value} should fit in two SMS segments");
        }
    }
}

/** Test helper: simulate a legacy row that predates the unsubscribe token. */
final class AlumniProfileTokenWipe
{
    public static function of(AlumniProfile $profile): void
    {
        DB::table('alumni_profiles')->where('id', $profile->id)->update(['unsubscribe_token' => null]);
    }
}

<?php

namespace Tests\Feature\Phase2;

use App\Enums\NotificationStatus;
use App\Services\Messaging\CloudWhatsAppGateway;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Contracts\WhatsAppGateway;
use App\Services\Messaging\LogSmsGateway;
use App\Services\Messaging\MtnSmsGateway;
use App\Services\Messaging\PermanentGatewayException;
use App\Services\Messaging\TransientGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two provider adapters against faked HTTP. These prove the requests are shaped as the
 * providers' documentation describes and that every failure is classified as "retry" or "give up".
 * They cannot prove a live MTN or Meta account accepts them; that needs real credentials.
 */
class GatewayAdaptersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.mtn_sms' => [
                'base_url' => 'https://mtn.test/v1/messages',
                'token_url' => 'https://mtn.test/oauth/token',
                'client_id' => 'my-id',
                'client_secret' => 'my-secret',
                'sender' => 'SorotiUni',
                'client_ref' => 'sunates',
            ],
            'services.whatsapp' => [
                'graph_url' => 'https://graph.test',
                'graph_version' => 'v21.0',
                'phone_number_id' => '1234567890',
                'access_token' => 'wa-token',
                'verify_token' => 'v',
                'app_secret' => 's',
            ],
        ]);
    }

    // ---- MTN SMS -----------------------------------------------------------------------

    private function mtnHappyPath(?array $sendResponse = null): void
    {
        Http::fake([
            'mtn.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => '3599']),
            'mtn.test/v1/messages/sms' => Http::response($sendResponse ?? ['messages' => [['to' => '+256700111222', 'deliveryStatus' => 'PENDING', 'messageId' => 'mtn-42']]], 201),
        ]);
    }

    public function test_mtn_signs_in_then_sends_in_the_documented_shape(): void
    {
        $this->mtnHappyPath();

        $result = (new MtnSmsGateway)->send('+256700111222', 'Hello Amina');

        $this->assertSame('mtn', $result->provider);
        $this->assertSame('mtn-42', $result->providerMessageId);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://mtn.test/oauth/token'
            && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'my-id' && $r['client_secret'] === 'my-secret');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://mtn.test/v1/messages/sms'
            && $r->hasHeader('Authorization', 'Bearer tok-1')
            && $r['to'] === ['+256700111222'] && $r['body'] === 'Hello Amina'
            && $r['from'] === 'SorotiUni' && $r['clientId'] === 'sunates');
    }

    public function test_the_mtn_token_is_reused_until_it_expires(): void
    {
        $this->mtnHappyPath();
        $gateway = new MtnSmsGateway;

        $gateway->send('+256700111222', 'one');
        $gateway->send('+256700111222', 'two');

        Http::assertSentCount(3); // one token request, two sends
    }

    public function test_the_sender_is_left_out_when_none_is_configured(): void
    {
        config(['services.mtn_sms.sender' => null]);
        $this->mtnHappyPath();

        (new MtnSmsGateway)->send('+256700111222', 'Hello');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sms') && ! array_key_exists('from', $r->data()));
    }

    public function test_missing_mtn_credentials_are_a_permanent_configuration_error(): void
    {
        config(['services.mtn_sms.client_secret' => null]);
        Http::fake();

        $this->expectException(PermanentGatewayException::class);
        $this->expectExceptionMessage('MTN_SMS_CLIENT_SECRET');

        (new MtnSmsGateway)->send('+256700111222', 'Hello');
    }

    public function test_refused_mtn_credentials_are_permanent(): void
    {
        Http::fake(['mtn.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(PermanentGatewayException::class);
        $this->expectExceptionMessage('refused the credentials');

        (new MtnSmsGateway)->send('+256700111222', 'Hello');
    }

    public function test_an_mtn_outage_while_signing_in_is_temporary(): void
    {
        Http::fake(['mtn.test/oauth/token' => Http::response('', 503)]);

        $this->expectException(TransientGatewayException::class);

        (new MtnSmsGateway)->send('+256700111222', 'Hello');
    }

    public function test_mtn_client_errors_are_permanent_and_server_errors_temporary(): void
    {
        // One fake whose answer changes per iteration: Http::fake() calls stack up and the first match wins.
        $status = 400;
        Http::fake(function (Request $r) use (&$status) { // by reference: an arrow fn would freeze the first value
            return str_contains($r->url(), 'oauth')
                ? Http::response(['access_token' => 't', 'expires_in' => 3600])
                : Http::response(['error' => 'x'], $status);
        });

        foreach ([[400, PermanentGatewayException::class], [403, PermanentGatewayException::class], [429, TransientGatewayException::class], [500, TransientGatewayException::class], [503, TransientGatewayException::class]] as [$status, $expected]) {
            Cache::flush();

            try {
                (new MtnSmsGateway)->send('+256700111222', 'Hello');
                $this->fail("HTTP {$status} should have thrown");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($expected, $e, "HTTP {$status}");
            }
        }
    }

    public function test_an_expired_mtn_token_is_dropped_so_the_retry_signs_in_again(): void
    {
        Http::fake([
            'mtn.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'old', 'expires_in' => 3600])
                ->push(['access_token' => 'new', 'expires_in' => 3600]),
            'mtn.test/v1/messages/sms' => Http::sequence()
                ->push(['error' => 'expired'], 401)
                ->push(['messages' => [['messageId' => 'm1', 'deliveryStatus' => 'PENDING']]], 201),
        ]);
        $gateway = new MtnSmsGateway;

        try {
            $gateway->send('+256700111222', 'Hello');
            $this->fail('the 401 should ask for a retry');
        } catch (TransientGatewayException) {
            // expected
        }

        $this->assertSame('m1', $gateway->send('+256700111222', 'Hello')->providerMessageId);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sms') && $r->hasHeader('Authorization', 'Bearer new'));
    }

    public function test_a_network_failure_talking_to_mtn_is_temporary(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(TransientGatewayException::class);

        (new MtnSmsGateway)->send('+256700111222', 'Hello');
    }

    public function test_a_message_mtn_accepts_but_marks_rejected_is_permanent(): void
    {
        $this->mtnHappyPath(['messages' => [['to' => '+256700111222', 'deliveryStatus' => 'REJECTED', 'messageId' => 'x']]]);

        $this->expectException(PermanentGatewayException::class);

        (new MtnSmsGateway)->send('+256700111222', 'Hello');
    }

    public function test_mtn_delivery_status_is_translated(): void
    {
        $cases = ['DELIVERED' => NotificationStatus::Delivered, 'READ' => NotificationStatus::Read, 'UNDELIVERABLE' => NotificationStatus::Failed,
            'EXPIRED' => NotificationStatus::Failed, 'PENDING' => NotificationStatus::Sent, 'SENT' => NotificationStatus::Sent];

        $reported = '';
        Http::fake(function (Request $r) use (&$reported) {
            return str_contains($r->url(), 'oauth')
                ? Http::response(['access_token' => 't', 'expires_in' => 3600])
                : Http::response([['to' => '+256700111222', 'deliveryStatus' => $reported]]);
        });

        foreach ($cases as $reported => $expected) {
            Cache::flush();

            $this->assertSame($expected, (new MtnSmsGateway)->deliveryStatus('abc'), $reported);
        }
    }

    public function test_an_unknown_mtn_delivery_status_is_left_alone(): void
    {
        Http::fake([
            'mtn.test/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'mtn.test/v1/messages/sms/abc/status' => Http::response([['deliveryStatus' => 'SOMETHING_NEW']]),
        ]);

        $this->assertNull((new MtnSmsGateway)->deliveryStatus('abc'));
    }

    // ---- WhatsApp Cloud API ------------------------------------------------------------

    public function test_whatsapp_sends_a_template_in_the_documented_shape(): void
    {
        Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.HBg']]])]);

        $result = (new CloudWhatsAppGateway)->sendTemplate('+256700111222', 'sunates_survey_invite', 'en', ['Amina', '6 months', 'https://a.example/s/x']);

        $this->assertSame('whatsapp', $result->provider);
        $this->assertSame('wamid.HBg', $result->providerMessageId);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.test/v21.0/1234567890/messages'
            && $r->hasHeader('Authorization', 'Bearer wa-token')
            && $r['messaging_product'] === 'whatsapp'
            && $r['to'] === '256700111222'
            && $r['type'] === 'template'
            && $r['template']['name'] === 'sunates_survey_invite'
            && $r['template']['language']['code'] === 'en'
            && $r['template']['components'] === [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => 'Amina'],
                    ['type' => 'text', 'text' => '6 months'],
                    ['type' => 'text', 'text' => 'https://a.example/s/x'],
                ],
            ]]);
    }

    public function test_a_template_without_parameters_sends_no_components(): void
    {
        Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'w1']]])]);

        (new CloudWhatsAppGateway)->sendTemplate('+256700111222', 'hello_world', 'en', []);

        Http::assertSent(fn (Request $r) => ! array_key_exists('components', $r['template']));
    }

    public function test_whatsapp_refusals_are_classified(): void
    {
        $cases = [
            [400, 131026, PermanentGatewayException::class],   // recipient not on WhatsApp / undeliverable
            [400, 132001, PermanentGatewayException::class],   // template does not exist
            [401, 190, PermanentGatewayException::class],      // bad access token
            [400, 131056, TransientGatewayException::class],   // pair rate limit
            [400, 130429, TransientGatewayException::class],   // throughput
            [429, 0, TransientGatewayException::class],
            [500, 0, TransientGatewayException::class],
            [503, 0, TransientGatewayException::class],
        ];

        $status = 400;
        $code = 0;
        Http::fake(function () use (&$status, &$code) {
            return Http::response(['error' => ['code' => $code, 'message' => 'x']], $status);
        });

        foreach ($cases as [$status, $code, $expected]) {
            try {
                (new CloudWhatsAppGateway)->sendTemplate('+256700111222', 't', 'en', []);
                $this->fail("HTTP {$status} / error {$code} should have thrown");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($expected, $e, "HTTP {$status} / error {$code}");
            }
        }
    }

    public function test_missing_whatsapp_configuration_is_a_permanent_error(): void
    {
        config(['services.whatsapp.access_token' => null]);
        Http::fake();

        $this->expectException(PermanentGatewayException::class);
        $this->expectExceptionMessage('WHATSAPP_ACCESS_TOKEN');

        (new CloudWhatsAppGateway)->sendTemplate('+256700111222', 't', 'en', []);
    }

    public function test_a_network_failure_talking_to_whatsapp_is_temporary(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(TransientGatewayException::class);

        (new CloudWhatsAppGateway)->sendTemplate('+256700111222', 't', 'en', []);
    }

    // ---- driver selection --------------------------------------------------------------

    public function test_an_unknown_driver_is_an_error_not_a_silent_fallback(): void
    {
        config(['sunates.messaging.sms_driver' => 'mnt']); // a typo

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown SMS_DRIVER 'mnt'");

        app(SmsGateway::class);
    }

    public function test_the_configured_drivers_are_resolved(): void
    {
        config(['sunates.messaging.sms_driver' => 'mtn', 'sunates.messaging.whatsapp_driver' => 'cloud']);

        $this->assertInstanceOf(MtnSmsGateway::class, app(SmsGateway::class));
        $this->assertInstanceOf(CloudWhatsAppGateway::class, app(WhatsAppGateway::class));

        config(['sunates.messaging.sms_driver' => 'log', 'sunates.messaging.whatsapp_driver' => 'log']);
        $this->assertInstanceOf(LogSmsGateway::class, app(SmsGateway::class));
    }
}

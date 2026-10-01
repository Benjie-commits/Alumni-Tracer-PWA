<?php

namespace App\Services\Messaging;

use App\Services\Messaging\Contracts\WhatsAppGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Platform, Cloud API (spec section 7.3): Meta's supported route for automated
 * business messaging, which avoids the terms-of-service risk of unofficial WhatsApp libraries.
 *
 * Business-initiated messages must be templates approved in Meta Business Manager; the template
 * names live in config/sunates.php. Success here means Meta accepted the request. Delivery and read
 * receipts arrive later on the webhook (see WhatsAppWebhookController).
 */
class CloudWhatsAppGateway implements WhatsAppGateway
{
    /** Meta error codes that are about load or timing, not about this message. */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 80007, 130429, 131000, 131056];

    public function sendTemplate(string $toE164, string $template, string $language, array $bodyParams): GatewayResult
    {
        $config = config('services.whatsapp');

        foreach (['phone_number_id', 'access_token'] as $required) {
            if (empty($config[$required])) {
                throw new PermanentGatewayException('WhatsApp is not configured (missing '.strtoupper("WHATSAPP_{$required}").').');
            }
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($toE164, '+'),
            'type' => 'template',
            'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $bodyParams === [] ? null : [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], $bodyParams),
                ]],
            ]),
        ];

        try {
            $response = Http::withToken($config['access_token'])->acceptJson()->timeout(20)->post(
                "{$config['graph_url']}/{$config['graph_version']}/{$config['phone_number_id']}/messages",
                $payload
            );
        } catch (ConnectionException $e) {
            throw new TransientGatewayException('Could not reach WhatsApp: '.$e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            return new GatewayResult('whatsapp', $response->json('messages.0.id'));
        }

        $code = (int) $response->json('error.code');
        $detail = 'WhatsApp answered HTTP '.$response->status().($code ? " (error {$code})" : '').': '
            .mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 300, '…');

        if ($response->status() === 429 || $response->serverError() || in_array($code, self::TRANSIENT_CODES, true)) {
            throw new TransientGatewayException($detail);
        }

        // 131026 (recipient not on WhatsApp / undeliverable), template problems, bad tokens: retrying is pointless.
        throw new PermanentGatewayException($detail);
    }
}

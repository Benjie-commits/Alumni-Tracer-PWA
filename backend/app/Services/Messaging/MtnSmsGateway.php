<?php

namespace App\Services\Messaging;

use App\Enums\NotificationStatus;
use App\Services\Messaging\Contracts\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MTN SMS API adapter (spec section 7.2).
 *
 * Written to MTN's published Swagger: an OAuth2 client-credentials token, then POST {base}/sms with
 * {to:[...], body, from, clientId} answering 201 {messages:[{messageId, deliveryStatus}]}, and
 * GET {base}/sms/{messageId}/status for delivery. It has NOT been run against a live MTN account
 * (that needs the university's sandbox credentials), so test it with the console's "send a test
 * message" before relying on it; every URL is configurable in config/services.php.
 */
class MtnSmsGateway implements SmsGateway
{
    private const TOKEN_CACHE_KEY = 'mtn_sms_access_token';

    public function send(string $toE164, string $message): GatewayResult
    {
        $config = $this->config();

        $payload = array_filter([
            'to' => [$toE164],
            'body' => $message,
            'from' => $config['sender'] ?: null,
            'clientId' => $config['client_ref'],
        ], fn ($value) => $value !== null);

        $response = $this->call(fn (PendingRequest $http) => $http->post("{$config['base_url']}/sms", $payload));

        $first = $response->json('messages.0') ?? [];
        $status = strtoupper((string) ($first['deliveryStatus'] ?? ''));

        if (in_array($status, ['REJECTED', 'UNDELIVERABLE'], true)) {
            throw new PermanentGatewayException("MTN rejected the message ({$status}).");
        }

        return new GatewayResult('mtn', $first['messageId'] ?? null);
    }

    public function deliveryStatus(string $providerMessageId): ?NotificationStatus
    {
        $config = $this->config();

        $response = $this->call(fn (PendingRequest $http) => $http->get("{$config['base_url']}/sms/{$providerMessageId}/status"));

        // The status endpoint answers with a list; the newest entry is last.
        $entries = $response->json() ?? [];
        $latest = is_array($entries) ? end($entries) : null;

        return match (strtoupper((string) data_get($latest, 'deliveryStatus'))) {
            'DELIVERED' => NotificationStatus::Delivered,
            'READ' => NotificationStatus::Read,
            'UNDELIVERABLE', 'REJECTED', 'EXPIRED', 'DELETED' => NotificationStatus::Failed,
            'PENDING', 'SENT' => NotificationStatus::Sent,
            default => null,
        };
    }

    /**
     * Run a request with a bearer token, translating every failure into "retry" or "give up".
     *
     * @param  callable(PendingRequest): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            $response = $request(Http::withToken($this->token())->acceptJson()->timeout(20));
        } catch (ConnectionException $e) {
            throw new TransientGatewayException('Could not reach MTN: '.$e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $detail = 'MTN answered HTTP '.$response->status().': '.mb_strimwidth((string) $response->body(), 0, 300, '…');

        if ($response->status() === 401) {
            // Token expired or revoked: drop it so the retry authenticates afresh.
            Cache::forget(self::TOKEN_CACHE_KEY);
            throw new TransientGatewayException($detail);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new TransientGatewayException($detail);
        }

        throw new PermanentGatewayException($detail);
    }

    private function token(): string
    {
        $config = $this->config();

        if ($cached = Cache::get(self::TOKEN_CACHE_KEY)) {
            return $cached;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post($config['token_url'], [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ]);
        } catch (ConnectionException $e) {
            throw new TransientGatewayException('Could not reach MTN to sign in: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            $message = 'MTN refused the credentials (HTTP '.$response->status().'). Check MTN_SMS_CLIENT_ID and MTN_SMS_CLIENT_SECRET.';

            throw $response->serverError() || $response->status() === 429
                ? new TransientGatewayException($message)
                : new PermanentGatewayException($message);
        }

        $token = (string) $response->json('access_token');

        // Keep it until a minute before MTN says it expires (expires_in may arrive as a string).
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('services.mtn_sms');

        foreach (['client_id', 'client_secret'] as $required) {
            if (empty($config[$required])) {
                throw new PermanentGatewayException('MTN SMS is not configured (missing '.strtoupper("MTN_SMS_{$required}").').');
            }
        }

        return $config;
    }
}

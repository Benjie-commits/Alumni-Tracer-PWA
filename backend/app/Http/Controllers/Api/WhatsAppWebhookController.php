<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta's callbacks for the WhatsApp Business Platform (spec section 7.3): delivery receipts for
 * messages we sent, and replies. A reply of "STOP" ends messaging on every channel.
 *
 * Anyone on the internet can POST here, so nothing is believed unless it carries a valid
 * X-Hub-Signature-256 made with the Meta app secret. With no secret configured, everything is refused.
 */
class WhatsAppWebhookController extends Controller
{
    /** How far along a message is; a late "sent" receipt must never undo "delivered". */
    private const RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    private const STOP_WORDS = ['stop', 'unsubscribe', 'cancel', 'end', 'quit', 'optout', 'opt out'];

    /** Meta calls this once, when you register the webhook URL. */
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.whatsapp.verify_token');

        if ($expected !== ''
            && $this->hubParam($request, 'mode') === 'subscribe'
            && hash_equals($expected, $this->hubParam($request, 'verify_token'))) {
            return response($this->hubParam($request, 'challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** Meta sends "hub.mode"; PHP may rewrite the dot to an underscore, so accept either. */
    private function hubParam(Request $request, string $name): string
    {
        $all = $request->query->all();

        return (string) ($all["hub_{$name}"] ?? $all["hub.{$name}"] ?? '');
    }

    public function receive(Request $request): Response
    {
        if (! $this->signatureIsValid($request)) {
            return response('Invalid signature', 403);
        }

        foreach ((array) $request->json('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->applyStatus($status);
                }
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $this->applyReply($message);
                }
            }
        }

        // Always 200 once the signature is good: Meta retries anything else, and there is nothing to retry.
        return response('', 200);
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($header, 7));
    }

    /** @param  array<string, mixed>  $status */
    private function applyStatus(array $status): void
    {
        $log = NotificationLog::query()->where('provider_message_id', $status['id'] ?? '')->first();
        $reported = (string) ($status['status'] ?? '');

        if (! $log || ! in_array($reported, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $current = self::RANK[$log->status->value] ?? null;

        if ($reported === 'failed') {
            // A failure report after delivery is stale noise; before delivery it is real.
            if ($current === null || $current < self::RANK['delivered']) {
                $error = $status['errors'][0] ?? [];
                $log->update([
                    'status' => NotificationStatus::Failed,
                    'failed_at' => now(),
                    'error' => trim(($error['title'] ?? $error['message'] ?? 'WhatsApp reported a delivery failure').(isset($error['code']) ? " (error {$error['code']})" : '')),
                ]);
            }

            return;
        }

        if ($current !== null && self::RANK[$reported] <= $current) {
            return;
        }

        $log->update([
            'status' => NotificationStatus::from($reported),
            'delivered_at' => in_array($reported, ['delivered', 'read'], true) ? ($log->delivered_at ?? now()) : $log->delivered_at,
        ]);
    }

    /** @param  array<string, mixed>  $message */
    private function applyReply(array $message): void
    {
        if (($message['type'] ?? null) !== 'text') {
            return;
        }

        $text = mb_strtolower(trim((string) data_get($message, 'text.body'), " \t\n\r.!"));
        if (! in_array($text, self::STOP_WORDS, true)) {
            return;
        }

        $last9 = PhoneNumber::lastNine('+'.ltrim((string) ($message['from'] ?? ''), '+'));
        if ($last9 === null) {
            return;
        }

        // Numbers are stored as typed (spaces, dashes, 0700...), so compare on the last nine digits only.
        AlumniProfile::query()
            ->where(fn ($q) => $q
                ->whereRaw("RIGHT(REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', ''), 9) = ?", [$last9])
                ->orWhereRaw("RIGHT(REGEXP_REPLACE(COALESCE(whatsapp_number, ''), '[^0-9]', ''), 9) = ?", [$last9]))
            ->get()
            ->each(function (AlumniProfile $profile) {
                $profile->setOptOut(null, true);
                $profile->save();
            });
    }
}

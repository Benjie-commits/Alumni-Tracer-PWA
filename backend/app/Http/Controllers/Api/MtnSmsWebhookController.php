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
 * The callback URL given to MTN (spec section 7.2): delivery receipts for the SMS we sent, and
 * replies. A reply of "STOP" ends messaging on every channel.
 *
 * MTN's notifications are not signed, so the URL itself carries the secret
 * (/api/webhooks/mtn-sms/{secret}). With no MTN_SMS_CALLBACK_SECRET set the endpoint does not exist (404),
 * and a wrong secret is a 404 too, so there is nothing to probe. Status only ever moves forward.
 *
 * MTN's exact payload is confirmed against the sandbox; this reads the fields MTN's SMS API documents
 * (messageId / deliveryStatus, senderAddress / message) from a single object, a list, or one wrapped
 * in a "notifications" / "deliveryInfoNotification" envelope.
 */
class MtnSmsWebhookController extends Controller
{
    private const RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    private const STOP_WORDS = ['stop', 'unsubscribe', 'cancel', 'end', 'quit', 'optout', 'opt out'];

    public function receive(Request $request, string $secret): Response
    {
        $expected = (string) config('services.mtn_sms.callback_secret');

        abort_if($expected === '' || ! hash_equals($expected, $secret), 404);

        foreach ($this->items($request->json()->all()) as $item) {
            if (($id = $this->field($item, ['messageId', 'message_id', 'id', 'resourceURL'])) !== null
                && ($status = $this->field($item, ['deliveryStatus', 'delivery_status', 'status'])) !== null) {
                $this->applyStatus((string) $id, strtoupper((string) $status));
            }

            $from = $this->field($item, ['senderAddress', 'from', 'msisdn', 'sender']);
            $text = $this->field($item, ['message', 'body', 'text']);
            if ($from !== null && $text !== null) {
                $this->applyReply((string) $from, (string) $text);
            }
        }

        // Always 200 once the secret is right: MTN retries anything else and there is nothing to retry.
        return response('', 200);
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        foreach (['notifications', 'deliveryInfoNotification', 'messages', 'data'] as $envelope) {
            if (isset($payload[$envelope]) && is_array($payload[$envelope])) {
                $payload = $payload[$envelope];
                break;
            }
        }

        $list = array_is_list($payload) ? $payload : [$payload];

        return array_values(array_filter($list, 'is_array'));
    }

    /** @param  array<string, mixed>  $item */
    private function field(array $item, array $names): ?string
    {
        foreach ($names as $name) {
            $value = data_get($item, $name) ?? data_get($item, "deliveryInfo.{$name}");
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function applyStatus(string $providerId, string $reported): void
    {
        $log = NotificationLog::query()->where('provider_message_id', $providerId)->first();

        if (! $log) {
            return;
        }

        $new = match ($reported) {
            'DELIVERED' => NotificationStatus::Delivered,
            'READ' => NotificationStatus::Read,
            'UNDELIVERABLE', 'REJECTED', 'EXPIRED', 'DELETED', 'FAILED' => NotificationStatus::Failed,
            'PENDING', 'SENT' => NotificationStatus::Sent,
            default => null,
        };

        if ($new === null) {
            return;
        }

        $current = self::RANK[$log->status->value] ?? null;

        if ($new === NotificationStatus::Failed) {
            // A failure report after delivery is stale noise; before delivery it is real.
            if ($current === null || $current < self::RANK['delivered']) {
                $log->update(['status' => NotificationStatus::Failed, 'failed_at' => now(), 'error' => "MTN reported {$reported}."]);
            }

            return;
        }

        if ($current !== null && self::RANK[$new->value] <= $current) {
            return;
        }

        $log->update([
            'status' => $new,
            'delivered_at' => in_array($new, [NotificationStatus::Delivered, NotificationStatus::Read], true) ? ($log->delivered_at ?? now()) : $log->delivered_at,
        ]);
    }

    private function applyReply(string $from, string $text): void
    {
        if (! in_array(mb_strtolower(trim($text, " \t\n\r.!")), self::STOP_WORDS, true)) {
            return;
        }

        $last9 = PhoneNumber::lastNine('+'.ltrim($from, '+'));
        if ($last9 === null) {
            return;
        }

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

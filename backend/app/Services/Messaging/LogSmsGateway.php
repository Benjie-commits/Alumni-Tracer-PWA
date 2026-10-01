<?php

namespace App\Services\Messaging;

use App\Enums\NotificationStatus;
use App\Services\Messaging\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development driver: writes the message to the Laravel log and sends nothing. The text (including
 * any survey link) is logged so you can click it while testing; do not leave SMS_DRIVER=log in production.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $toE164, string $message): GatewayResult
    {
        Log::info('[sms:log] would send', ['to' => $toE164, 'message' => $message]);

        return new GatewayResult('log', 'log-sms-'.Str::random(12));
    }

    public function deliveryStatus(string $providerMessageId): ?NotificationStatus
    {
        return null;
    }
}

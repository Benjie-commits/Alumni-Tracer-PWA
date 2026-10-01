<?php

namespace App\Services\Messaging;

use App\Services\Messaging\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Development driver: logs the template and its parameters, sends nothing. */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function sendTemplate(string $toE164, string $template, string $language, array $bodyParams): GatewayResult
    {
        Log::info('[whatsapp:log] would send', ['to' => $toE164, 'template' => $template, 'language' => $language, 'params' => $bodyParams]);

        return new GatewayResult('log', 'log-wa-'.Str::random(12));
    }
}

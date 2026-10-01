<?php

namespace App\Services\Messaging\Contracts;

use App\Services\Messaging\GatewayResult;
use App\Services\Messaging\PermanentGatewayException;
use App\Services\Messaging\TransientGatewayException;

interface WhatsAppGateway
{
    /**
     * Send a pre-approved template message (the only kind allowed outside WhatsApp's 24-hour window).
     *
     * @param  string  $toE164  recipient in E.164 form
     * @param  list<string>  $bodyParams  values for the template's {{1}}, {{2}}... in order
     *
     * @throws PermanentGatewayException
     * @throws TransientGatewayException
     */
    public function sendTemplate(string $toE164, string $template, string $language, array $bodyParams): GatewayResult;
}

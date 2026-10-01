<?php

namespace App\Services\Messaging\Contracts;

use App\Enums\NotificationStatus;
use App\Services\Messaging\GatewayResult;
use App\Services\Messaging\PermanentGatewayException;
use App\Services\Messaging\TransientGatewayException;

/**
 * Spec section 7.2: SMS sits behind an interface so a second aggregator can be added later without
 * touching the survey or nudge logic.
 */
interface SmsGateway
{
    /**
     * @param  string  $toE164  recipient in E.164 form, e.g. +256700123456
     *
     * @throws PermanentGatewayException
     * @throws TransientGatewayException
     */
    public function send(string $toE164, string $message): GatewayResult;

    /** Ask the provider how an earlier message ended up; null when it cannot tell (yet). */
    public function deliveryStatus(string $providerMessageId): ?NotificationStatus;
}

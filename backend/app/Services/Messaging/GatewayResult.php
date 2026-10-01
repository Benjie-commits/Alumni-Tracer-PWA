<?php

namespace App\Services\Messaging;

/** What a provider told us when it accepted a message. Acceptance is not delivery. */
final readonly class GatewayResult
{
    public function __construct(
        public string $provider,
        public ?string $providerMessageId,
    ) {}
}

<?php

namespace Tests\Support;

use App\Enums\NotificationStatus;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\GatewayResult;
use Throwable;

/** Records what would have been sent; set failWith to make the next sends fail. */
class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public ?Throwable $failWith = null;

    /** @var array<string, NotificationStatus> provider id => what the provider will report */
    public array $statuses = [];

    public function send(string $toE164, string $message): GatewayResult
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $this->sent[] = ['to' => $toE164, 'message' => $message];

        return new GatewayResult('fake-sms', 'sms-'.count($this->sent));
    }

    public function deliveryStatus(string $providerMessageId): ?NotificationStatus
    {
        return $this->statuses[$providerMessageId] ?? null;
    }
}

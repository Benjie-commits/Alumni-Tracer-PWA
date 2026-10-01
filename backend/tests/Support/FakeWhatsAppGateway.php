<?php

namespace Tests\Support;

use App\Services\Messaging\Contracts\WhatsAppGateway;
use App\Services\Messaging\GatewayResult;
use Throwable;

/** Records what would have been sent; set failWith to make the next sends fail. */
class FakeWhatsAppGateway implements WhatsAppGateway
{
    /** @var list<array{to: string, template: string, language: string, params: list<string>}> */
    public array $sent = [];

    public ?Throwable $failWith = null;

    public function sendTemplate(string $toE164, string $template, string $language, array $bodyParams): GatewayResult
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $this->sent[] = ['to' => $toE164, 'template' => $template, 'language' => $language, 'params' => $bodyParams];

        return new GatewayResult('fake-whatsapp', 'wamid.'.count($this->sent));
    }
}

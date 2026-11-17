<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('sunates:erp-token')]
#[Description('Make a key for SorotiUniERP to push graduates with (nothing is saved; put it in .env yourself)')]
class MakeErpToken extends Command
{
    public function handle(): int
    {
        $token = Str::random(48);

        $this->line('Add this to backend/.env on the server, then give the same value to the ERP team over a secure channel:');
        $this->newLine();
        $this->line("ERP_INBOUND_TOKEN={$token}");
        $this->newLine();
        $this->line('The endpoint switches on as soon as a token is set (a restart of the web server and queue worker picks it up).');
        $this->line('Changing or removing the value switches the old key off. Nothing was written to any file.');

        return self::SUCCESS;
    }
}

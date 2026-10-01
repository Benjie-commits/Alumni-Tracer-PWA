<?php

namespace App\Services\Erp;

/** Result of "test the connection": a verdict and a sentence, never any graduate's details. */
final readonly class SourceCheck
{
    public function __construct(public bool $ok, public string $message) {}
}

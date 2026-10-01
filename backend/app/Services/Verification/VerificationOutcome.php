<?php

namespace App\Services\Verification;

use App\Enums\VerificationResult;

/**
 * What a lookup tells the requester. For a verified graduate that is the programme and year and
 * nothing more (spec section 9): no student number, contact details, class of award or school.
 * `name` is only filled for a link the alumnus chose to share, where showing whose degree it is
 * is the point.
 */
final readonly class VerificationOutcome
{
    public function __construct(
        public VerificationResult $result,
        public string $reference,
        public ?string $programme = null,
        public ?int $graduationYear = null,
        public ?string $name = null,
        /** For an ambiguous name-only lookup: adding programme and year may settle it. */
        public bool $canNarrow = false,
    ) {}

    public function message(): string
    {
        return match ($this->result) {
            VerificationResult::Verified => 'Verified: Soroti University confirms this person graduated'
                .($this->programme ? " with {$this->programme}" : '').($this->graduationYear ? " in {$this->graduationYear}" : '').'.',
            VerificationResult::NotFound => 'We could not find a graduate matching those details. Check the spelling and the year, or ask the Registrar\'s office to check.',
            VerificationResult::Ambiguous => $this->canNarrow
                ? 'More than one graduate has this name. Add the programme and the year of graduation to narrow it down.'
                : 'We cannot tell these graduates apart automatically. Please ask the Registrar\'s office to check.',
        };
    }

    /** Whether to offer "ask the Registrar to check" (a verified answer needs no escalation). */
    public function canEscalate(): bool
    {
        return $this->result !== VerificationResult::Verified && ! $this->canNarrow;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'result' => $this->result->value,
            'reference' => $this->reference,
            'message' => $this->message(),
            'programme' => $this->programme,
            'graduation_year' => $this->graduationYear,
            'name' => $this->name,
            'can_narrow' => $this->canNarrow ?: null,
        ], fn ($v) => $v !== null);
    }
}

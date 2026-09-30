<?php

namespace App\Services\Import;

/**
 * Outcome of a bulk import (or dry run) so staff can see exactly what happened to each row.
 */
class ImportReport
{
    public const MAX_MESSAGES = 200;

    public int $rows = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $skipped = 0;

    public int $referenceCreated = 0;

    /** @var list<array{row: int, message: string}> */
    public array $errors = [];

    /** @var list<array{row: int, message: string}> */
    public array $warnings = [];

    public function __construct(public readonly bool $dryRun) {}

    public function error(int $row, string $message): void
    {
        $this->skipped++;

        if (count($this->errors) < self::MAX_MESSAGES) {
            $this->errors[] = ['row' => $row, 'message' => $message];
        }
    }

    public function warn(int $row, string $message): void
    {
        if (count($this->warnings) < self::MAX_MESSAGES) {
            $this->warnings[] = ['row' => $row, 'message' => $message];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'rows' => $this->rows,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'reference_created' => $this->referenceCreated,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}

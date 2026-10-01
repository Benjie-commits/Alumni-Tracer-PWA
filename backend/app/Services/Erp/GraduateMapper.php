<?php

namespace App\Services\Erp;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns one ERP record into the row our import rules understand, using the field map in config.
 *
 * This is the only place that knows what the ERP calls things, so an ERP with different field names
 * needs a different map and nothing else.
 */
class GraduateMapper
{
    /** Columns the import service reads; each is looked up in the ERP record by its mapped name. */
    public const COLUMNS = [
        'student_number', 'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth',
        'school', 'department', 'programme', 'graduation_year', 'graduation_date', 'class_of_award',
        'email', 'phone',
    ];

    public const REQUIRED = ['student_number', 'first_name', 'last_name'];

    private const DATES = ['date_of_birth', 'graduation_date'];

    /**
     * @param  array<string, string|null>  $fields  our column => the ERP's field (null: not provided)
     * @param  list<string>  $graduatedValues  status values that mean "has graduated"
     */
    public function __construct(private readonly array $fields, private readonly array $graduatedValues) {}

    public static function fromConfig(): self
    {
        return new self(
            (array) config('sunates.erp.fields'),
            array_map(fn ($v) => strtolower(trim((string) $v)), (array) config('sunates.erp.graduated_values')),
        );
    }

    /** The ERP's name for one of our columns, or null when the ERP does not provide it. */
    public function field(string $column): ?string
    {
        $field = $this->fields[$column] ?? null;

        return is_string($field) && trim($field) !== '' ? trim($field) : null;
    }

    /**
     * Every ERP field we read, so a database query selects those columns and nothing else.
     *
     * @return list<string>
     */
    public function sourceFields(): array
    {
        $names = [];
        foreach ([...self::COLUMNS, 'status', 'updated_at'] as $column) {
            if (($name = $this->field($column)) !== null) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>|null null when the ERP says this person has not graduated (yet)
     */
    public function map(array $raw): ?array
    {
        if ($this->field('status') !== null && ! in_array(strtolower($this->value($raw, 'status')), $this->graduatedValues, true)) {
            return null;
        }

        $row = [];
        foreach (self::COLUMNS as $column) {
            $value = $this->value($raw, $column);
            $row[$column] = in_array($column, self::DATES, true) ? $this->isoDate($value) : $value;
        }

        return $row;
    }

    /**
     * Required columns this record has nothing for: a sign the map does not match the ERP.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    public function missingRequired(array $raw): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $column) => $this->value($raw, $column) === ''));
    }

    /** When the ERP says this record last changed, if it can. @param  array<string, mixed>  $raw */
    public function changedAt(array $raw): ?CarbonImmutable
    {
        $value = $this->value($raw, 'updated_at');
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('sunates.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $raw */
    private function value(array $raw, string $column): string
    {
        $field = $this->field($column);
        if ($field === null) {
            return '';
        }

        // Dot notation reaches into nested JSON ("programme.name"); a database row is flat.
        $value = array_key_exists($field, $raw) ? $raw[$field] : data_get($raw, $field);

        return match (true) {
            is_string($value) => trim($value),
            is_int($value), is_float($value) => (string) $value,
            default => '', // null, booleans and nested structures carry no usable text
        };
    }

    /** ERPs send "2024-07-15T00:00:00Z"; the import rules want the date alone. */
    private function isoDate(string $value): string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ].*)?$/', $value, $m) === 1 ? $m[1] : $value;
    }
}

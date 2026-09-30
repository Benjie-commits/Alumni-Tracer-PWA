<?php

namespace App\Services\Import;

use Generator;
use InvalidArgumentException;

/**
 * Reads a Registrar spreadsheet exported as CSV and yields rows keyed by canonical column name.
 *
 * Column headings are matched loosely (case, spaces, common synonyms such as "Surname" or
 * "Reg No") because the Registrar's existing spreadsheets were never built to a single template.
 */
class CsvReader
{
    /** @var array<string, list<string>> canonical column => accepted headings (already normalised) */
    private const ALIASES = [
        'student_number' => ['student_number', 'student_no', 'student_id', 'reg_no', 'reg_number', 'regno', 'registration_number', 'registration_no'],
        'first_name' => ['first_name', 'firstname', 'given_name', 'given_names', 'forename'],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name'],
        'other_names' => ['other_names', 'othernames', 'middle_name', 'middle_names'],
        'gender' => ['gender', 'sex'],
        'date_of_birth' => ['date_of_birth', 'dob', 'birth_date'],
        'school' => ['school', 'faculty', 'college'],
        'department' => ['department', 'dept'],
        'programme' => ['programme', 'program', 'course', 'degree_programme', 'degree'],
        'graduation_year' => ['graduation_year', 'year_of_graduation', 'grad_year', 'year'],
        'graduation_date' => ['graduation_date', 'date_of_graduation', 'graduated_on'],
        'class_of_award' => ['class_of_award', 'class', 'award_class', 'degree_class', 'classification'],
        'email' => ['email', 'email_address'],
        'phone' => ['phone', 'phone_number', 'telephone', 'mobile', 'contact'],
    ];

    public const REQUIRED = ['student_number', 'first_name', 'last_name'];

    /**
     * @return Generator<int, array<string, string>> spreadsheet row number => canonical column => value
     */
    public function read(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('The uploaded file could not be opened.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new InvalidArgumentException('The file is empty.');
            }
            $firstLine = $this->toUtf8(preg_replace('/^\xEF\xBB\xBF/', '', $firstLine));
            $delimiter = $this->detectDelimiter($firstLine);

            $columns = $this->mapHeaders(str_getcsv($firstLine, $delimiter, '"', ''));
            $missing = array_diff(self::REQUIRED, array_values($columns));
            if ($missing !== []) {
                throw new InvalidArgumentException(
                    'Missing required column(s): '.implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $missing)).'.'
                );
            }

            $row = 1;
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $row++;

                if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                    continue;
                }

                $mapped = [];
                foreach ($columns as $index => $canonical) {
                    $mapped[$canonical] = trim($this->toUtf8((string) ($cells[$index] ?? '')));
                }

                yield $row => $mapped;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string|null>  $headers
     * @return array<int, string> column index => canonical name (unknown columns are ignored)
     */
    private function mapHeaders(array $headers): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $normalised = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $header)), '_');

            foreach (self::ALIASES as $canonical => $aliases) {
                if (in_array($normalised, $aliases, true) && ! in_array($canonical, $columns, true)) {
                    $columns[$index] = $canonical;
                    break;
                }
            }
        }

        return $columns;
    }

    private function detectDelimiter(string $line): string
    {
        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t"] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $bestCount) {
                [$best, $bestCount] = [$candidate, $count];
            }
        }

        return $best;
    }

    /** Excel on Windows often saves CSV as Windows-1252; names with accents must survive. */
    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}

<?php

namespace App\Support;

/** Shared CSV export helpers. */
final class Csv
{
    /**
     * Alumni type their own names and employers, so a cell like =HYPERLINK(...) must not run when
     * staff open an export in Excel. Real phone numbers are left alone.
     */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^\+?[0-9][0-9\s\-]*$/', $value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * Write a UTF-8 (BOM, so Excel reads accents) CSV to the output stream.
     *
     * @param  list<string>  $headings
     * @param  iterable<list<mixed>>  $rows
     */
    public static function stream(array $headings, iterable $rows): void
    {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headings, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(self::safe(...), $row), ',', '"', '');
        }

        fclose($out);
    }
}

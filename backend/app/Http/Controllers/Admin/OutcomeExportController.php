<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Outcomes\OutcomeFilters;
use App\Services\Outcomes\OutcomeReport;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The outcome table as a spreadsheet (FR-4: "view and export"). Exactly what the screen shows,
 * including the withheld small groups, so an export can never reveal more than the dashboard does.
 */
class OutcomeExportController extends Controller
{
    public function __invoke(Request $request, OutcomeReport $outcomes): StreamedResponse
    {
        $report = $outcomes->build(OutcomeFilters::fromArray($request->query()));
        $filters = $report['filters'];
        $surveys = $filters->source === 'surveys';
        $min = $report['min'];

        $categoryHeadings = array_merge(...array_map(
            fn (string $label) => ["{$label} (count)", "{$label} %"],
            array_values(OutcomeReport::EMPLOYMENT),
        ));

        $headings = array_merge(
            [$filters->groupLabel(), $surveys ? 'Graduates who answered' : 'Graduates with a known status', 'In work %'],
            $categoryHeadings,
            ['Further study (now or planned) %', 'Started a business %', $report['rate_label'].' %', 'Closed surveys / records counted', 'Answered / with status'],
        );

        $pct = fn ($v) => $v === null ? '' : $v;

        $rows = function () use ($report, $pct, $min) {
            foreach (array_merge([$report['summary']], $report['rows']) as $row) {
                if ($row['suppressed']) {
                    // Same rule as the screen: a small group's figures are withheld.
                    yield array_merge([$row['label'], "fewer than {$min}"], array_fill(0, 1 + count(OutcomeReport::EMPLOYMENT) * 2 + 5, ''));

                    continue;
                }

                $line = [$row['label'], $row['n'], $pct($row['in_work_pct'])];
                foreach (array_keys(OutcomeReport::EMPLOYMENT) as $category) {
                    $line[] = $row['counts'][$category];
                    $line[] = $pct($row['pct'][$category]);
                }

                yield array_merge($line, [
                    $pct($row['further_study_pct']), $pct($row['business_pct']), $pct($row['rate_pct']),
                    $row['rate_of'], $row['rate_done'],
                ]);
            }
        };

        return response()->streamDownload(
            fn () => Csv::stream($headings, $rows()),
            'sunates-outcomes-'.now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}

<?php

namespace App\Services\Outcomes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FR-4: employment, further-study and entrepreneurship outcomes of graduates, by School,
 * Department, Programme and year, for Deans, the QA Directorate and Top Management.
 *
 * Everything here is an aggregate. A group with fewer respondents than `min_cell_size` is reported
 * as "fewer than N" with no percentages, so a table split finely enough cannot reveal what one
 * identifiable graduate said.
 */
class OutcomeReport
{
    /** Outcome categories in display order, with the survey/profile value that fills each. */
    public const EMPLOYMENT = [
        'employed' => 'Employed',
        'self_employed' => 'Self-employed',
        'unemployed' => 'Seeking work',
        'further_study' => 'Studying full time',
        'other' => 'Other',
    ];

    /**
     * @return array{
     *     filters: OutcomeFilters, source_label: string, rate_label: string, min: int,
     *     summary: array<string, mixed>, rows: list<array<string, mixed>>
     * }
     */
    public function build(OutcomeFilters $filters): array
    {
        $min = (int) config('sunates.dashboards.min_cell_size');
        $rates = $this->rates($filters);

        $summaryRow = $this->respondents($filters)->selectRaw($this->aggregates($filters))->first();
        $summary = $this->present(null, 'All graduates in this view', $summaryRow, $rates->get('__all__'), $min);

        $rows = $this->grouped($this->respondents($filters), $filters)
            ->selectRaw($this->aggregates($filters))
            ->groupBy('gid', 'glabel')
            ->get()
            ->map(fn ($r) => $this->present($r->gid, $r->glabel ?? 'Not assigned', $r, $rates->get((string) ($r->gid ?? '')), $min))
            ->sort(fn ($a, $b) => $filters->groupBy === 'year'
                ? ((int) $b['key'] <=> (int) $a['key'])
                : ([$b['n'], $a['label']] <=> [$a['n'], $b['label']]))
            ->values()
            ->all();

        return [
            'filters' => $filters,
            'source_label' => $filters->source === 'surveys'
                ? 'Tracer survey answers'.($filters->milestone ? " ({$filters->milestone} months after graduation)" : ' (each alumnus\'s most recent answer)')
                : 'Latest status alumni recorded on their own profile',
            'rate_label' => $filters->source === 'surveys' ? 'Response rate (closed surveys)' : 'Records with a known status',
            'min' => $min,
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    // ---- the people being counted --------------------------------------------------------

    /** One row per respondent, joined to their programme/department/school, narrowed by the filters. */
    private function respondents(OutcomeFilters $f): Builder
    {
        if ($f->source === 'profiles') {
            $query = $this->graduates()->whereNotNull('p.employment_status');
        } else {
            $query = DB::table('survey_responses as sr')
                ->join('survey_invitations as si', 'si.id', '=', 'sr.survey_invitation_id')
                ->join('alumni_profiles as p', 'p.id', '=', 'sr.alumni_profile_id')
                ->whereNull('p.deleted_at');

            if ($f->milestone) {
                $query->join('tracer_survey_cycles as c', 'c.id', '=', 'si.tracer_survey_cycle_id')->where('c.milestone_months', $f->milestone);
            } else {
                // People, not responses: an alumnus who answered at 6 months and again at a year counts once, as of their latest answer.
                $query->whereRaw('sr.id = (SELECT x.id FROM survey_responses x WHERE x.alumni_profile_id = sr.alumni_profile_id ORDER BY x.submitted_at DESC, x.id DESC LIMIT 1)');
            }
        }

        return $this->slice($query, $f);
    }

    /** Graduates the Registrar holds records for (the same population verification confirms). */
    private function graduates(): Builder
    {
        return DB::table('alumni_profiles as p')
            ->whereNull('p.deleted_at')
            ->whereIn('p.verification_status', ['unclaimed', 'verified'])
            ->whereNotNull('p.student_number')
            ->whereNotNull('p.graduation_year');
    }

    private function slice(Builder $query, OutcomeFilters $f): Builder
    {
        return $query
            ->leftJoin('programmes as pr', 'pr.id', '=', 'p.programme_id')
            ->leftJoin('departments as d', 'd.id', '=', 'pr.department_id')
            ->leftJoin('schools as s', 's.id', '=', 'd.school_id')
            ->when($f->schoolId, fn ($q, $id) => $q->where('s.id', $id))
            ->when($f->departmentId, fn ($q, $id) => $q->where('d.id', $id))
            ->when($f->programmeId, fn ($q, $id) => $q->where('pr.id', $id))
            ->when($f->yearFrom, fn ($q, $year) => $q->where('p.graduation_year', '>=', $year))
            ->when($f->yearTo, fn ($q, $year) => $q->where('p.graduation_year', '<=', $year));
    }

    private function grouped(Builder $query, OutcomeFilters $f): Builder
    {
        [$id, $label] = match ($f->groupBy) {
            'department' => ['d.id', "CONCAT(s.name, ' › ', d.name)"],
            'programme' => ['pr.id', 'pr.name'],
            'year' => ['p.graduation_year', 'CAST(p.graduation_year AS CHAR)'],
            default => ['s.id', 's.name'],
        };

        return $query->selectRaw("{$id} AS gid, {$label} AS glabel");
    }

    // ---- the numbers -----------------------------------------------------------------------

    private function aggregates(OutcomeFilters $f): string
    {
        $emp = $f->source === 'surveys' ? 'sr.employment_status' : 'p.employment_status';
        $fs = $f->source === 'surveys' ? 'sr.further_study_status' : 'p.further_study_status';
        // Profiles do not record "started a business", so it is simply unknown for that source.
        $sb = $f->source === 'surveys' ? 'sr.started_business' : 'NULL';

        $sum = fn (string $condition) => "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END)";

        return implode(', ', [
            'COUNT(*) AS n',
            ...array_map(fn (string $key) => $sum("{$emp} = '{$key}'")." AS {$key}", array_keys(self::EMPLOYMENT)),
            $sum("{$fs} IN ('studying', 'planned')").' AS further_study_any',
            $sum("{$sb} = 1").' AS business_yes',
            $sum("{$sb} IS NOT NULL").' AS business_answered',
        ]);
    }

    /**
     * Response rate per group (surveys) or how many graduate records have a known status (profiles).
     * Keyed by group id, with the overall figure under "__all__".
     *
     * @return Collection<string, array{done: int, of: int}>
     */
    private function rates(OutcomeFilters $f)
    {
        if ($f->source === 'surveys') {
            $make = function () use ($f) {
                $query = DB::table('survey_invitations as si')
                    ->join('alumni_profiles as p', 'p.id', '=', 'si.alumni_profile_id')
                    ->whereNull('p.deleted_at')
                    // Still-open surveys are not "missed" yet, so the rate is over surveys that have closed.
                    ->whereIn('si.status', ['completed', 'expired']);

                if ($f->milestone) {
                    $query->join('tracer_survey_cycles as c', 'c.id', '=', 'si.tracer_survey_cycle_id')->where('c.milestone_months', $f->milestone);
                }

                return $this->slice($query, $f);
            };
            $columns = "SUM(CASE WHEN si.status = 'completed' THEN 1 ELSE 0 END) AS done, COUNT(*) AS of_total";
        } else {
            $make = fn () => $this->slice($this->graduates(), $f);
            $columns = 'SUM(CASE WHEN p.employment_status IS NOT NULL THEN 1 ELSE 0 END) AS done, COUNT(*) AS of_total';
        }

        $all = $make()->selectRaw($columns)->first();
        $byGroup = $this->grouped($make(), $f)->selectRaw($columns)->groupBy('gid', 'glabel')->get();

        return $byGroup
            ->mapWithKeys(fn ($r) => [(string) ($r->gid ?? '') => ['done' => (int) $r->done, 'of' => (int) $r->of_total]])
            ->put('__all__', ['done' => (int) ($all->done ?? 0), 'of' => (int) ($all->of_total ?? 0)]);
    }

    /**
     * @param  array{done: int, of: int}|null  $rate
     * @return array<string, mixed>
     */
    private function present(mixed $key, string $label, ?object $r, ?array $rate, int $min): array
    {
        $n = (int) ($r->n ?? 0);
        $suppressed = $n < $min;

        // Percentages are withheld for small groups, and are never invented for an empty one.
        $pct = fn (int $count, int $of): ?float => ($suppressed || $of < $min || $of === 0) ? null : round($count / $of * 100, 1);

        $counts = [];
        foreach (array_keys(self::EMPLOYMENT) as $category) {
            $counts[$category] = (int) ($r->{$category} ?? 0);
        }

        $answered = (int) ($r->business_answered ?? 0);

        return [
            'key' => $key,
            'label' => $label,
            'n' => $n,
            'suppressed' => $suppressed,
            'counts' => $counts,
            'pct' => array_map(fn (int $c) => $pct($c, $n), $counts),
            'in_work_pct' => $pct($counts['employed'] + $counts['self_employed'], $n),
            'further_study_pct' => $pct((int) ($r->further_study_any ?? 0), $n),
            'business_pct' => $pct((int) ($r->business_yes ?? 0), $answered),
            'rate_pct' => $rate && $rate['of'] > 0 ? round($rate['done'] / $rate['of'] * 100, 1) : null,
            'rate_done' => $rate['done'] ?? 0,
            'rate_of' => $rate['of'] ?? 0,
        ];
    }
}

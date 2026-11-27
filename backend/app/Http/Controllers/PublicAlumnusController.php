<?php

namespace App\Http\Controllers;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Public pages for graduates who chose to publish one. Nothing here is reachable unless the graduate
 * published it AND the Registrar has verified the graduation; optional parts show only if switched on.
 */
class PublicAlumnusController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $people = $this->published()
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like);
            }))
            ->with('programme.department.school')
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(48)->get();

        return view('alumni.index', ['people' => $people, 'q' => $q]);
    }

    public function show(string $slug): View
    {
        $profile = $this->published()->where('public_slug', $slug)->with('programme.department.school')->firstOrFail();

        $jobs = collect();
        if ($profile->showsOnPublicPage('work')) {
            $jobs = $profile->employmentRecords()->orderByDesc('is_current')->orderByDesc('start_date')->get();
        }

        return view('alumni.show', [
            'p' => $profile,
            'jobs' => $jobs,
            'strip' => $this->careerStrip($jobs),
            'location' => $profile->showsOnPublicPage('location') ? collect([$profile->city, $profile->country])->filter()->implode(', ') : '',
            'mentorTopics' => $profile->showsOnPublicPage('mentor') && $profile->mentor_opt_in_at ? $profile->mentor_topics : null,
            'linkedin' => $profile->showsOnPublicPage('linkedin') && str_starts_with((string) $profile->linkedin_url, 'https://') ? $profile->linkedin_url : null,
        ]);
    }

    private function published()
    {
        return AlumniProfile::query()
            ->whereNotNull('public_published_at')
            ->whereNotNull('public_slug')
            ->where('verification_status', VerificationStatus::Verified);
    }

    /**
     * Each dated job as a bar on one shared time axis: [{job, left%, width%}], plus the axis years.
     * Jobs without a start date are left out of the strip (they still appear in the list).
     *
     * @return array{rows: list<array{job: mixed, left: float, width: float}>, years: list<array{year: int, left: float}>}|null
     */
    private function careerStrip($jobs): ?array
    {
        $dated = $jobs->filter(fn ($j) => $j->start_date !== null);
        if ($dated->isEmpty()) {
            return null;
        }

        $start = Carbon::parse($dated->min('start_date'))->startOfYear();
        $end = Carbon::now()->endOfYear();
        $span = max(1, $start->diffInDays($end));

        $rows = $dated->sortBy('start_date')->map(function ($j) use ($start, $end, $span) {
            $from = Carbon::parse($j->start_date);
            $to = $j->end_date ? Carbon::parse($j->end_date) : Carbon::now();
            $left = $start->diffInDays($from) / $span * 100;
            $width = max(1.5, $from->diffInDays($to->max($from)) / $span * 100);

            return ['job' => $j, 'left' => round($left, 2), 'width' => round(min($width, 100 - $left), 2)];
        })->values()->all();

        $years = [];
        for ($y = $start->year; $y <= $end->year; $y++) {
            $years[] = ['year' => $y, 'left' => round($start->diffInDays(Carbon::create($y, 1, 1)) / $span * 100, 2)];
        }

        return ['rows' => $rows, 'years' => count($years) > 7 ? array_values(array_filter($years, fn ($t, $i) => $i % 2 === 0, ARRAY_FILTER_USE_BOTH)) : $years];
    }
}

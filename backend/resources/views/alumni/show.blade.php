@php
    $fullName = trim($p->first_name.' '.$p->last_name);
    $programme = $p->programme;
    $span = function ($j) {
        $from = $j->start_date ? \Illuminate\Support\Carbon::parse($j->start_date)->format('M Y') : null;
        $to = $j->is_current ? 'Present' : ($j->end_date ? \Illuminate\Support\Carbon::parse($j->end_date)->format('M Y') : null);

        return $from ? $from.' – '.($to ?? '') : ($to ?? '');
    };
@endphp
<x-layouts.public :title="$fullName" :wide="true" robots="noindex, nofollow">
    <x-slot:head>
        <style>
            .hero { background: #1e5b3c; color: #fff; overflow: hidden; }
            .hero .in { max-width: 1040px; margin: 0 auto; padding: clamp(36px, 7vw, 76px) 20px clamp(40px, 7vw, 80px); display: grid; grid-template-columns: 1fr auto; gap: 32px; align-items: center; }
            .hero h1 { font-size: clamp(38px, 7vw, 68px); line-height: 1.03; letter-spacing: -.02em; margin: 0 0 14px; color: #fff; }
            .hero .headline { font-family: var(--display); font-weight: 400; font-style: italic; font-size: clamp(18px, 2.6vw, 24px); line-height: 1.35; max-width: 34ch; margin: 0 0 22px; color: #dbe6f3; text-wrap: balance; }
            .hero .tags { display: flex; flex-wrap: wrap; gap: 8px; }
            .hero .tag { padding: 6px 14px; border-radius: 999px; border: 1px solid rgba(255,255,255,.28); font-size: 14px; color: #eef4ee; }
            .hero .seal { color: #e8bb45; flex: none; display: block; }
            .hero .seal-wrap { transform: translateX(12%); }
            @media (max-width: 720px) { .hero .in { grid-template-columns: 1fr; } .hero .seal-wrap { order: -1; transform: none; } .hero .seal { width: 128px; height: 128px; } }
            .layout { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 28px; align-items: start; }
            @media (max-width: 860px) { .layout { grid-template-columns: 1fr; } }
            .layout .card { margin: 0 0 20px; }
            .journey { list-style: none; margin: 0; padding: 0; position: relative; }
            .journey::before { content: ""; position: absolute; left: 6px; top: 8px; bottom: 8px; width: 2px; background: var(--line); }
            .journey li { position: relative; padding: 0 0 22px 32px; }
            .journey li:last-child { padding-bottom: 0; }
            .journey li::before { content: ""; position: absolute; left: 0; top: 6px; width: 14px; height: 14px; border-radius: 50%; background: var(--panel); border: 3px solid var(--brand); }
            .journey li.now::before { border-color: var(--gold); background: var(--gold); }
            .journey li.grad::before { border-color: var(--gold); background: var(--panel); }
            .journey .when { font-size: 13px; color: var(--muted); font-variant-numeric: tabular-nums; }
            .journey .what { font-family: var(--display); font-weight: 600; font-size: 19px; line-height: 1.25; margin: 1px 0 2px; }
            .journey .where { color: var(--muted); font-size: 15px; }
            .strip { position: relative; margin-top: 4px; padding: 0 18px; }
            .strip .axis { position: relative; height: 22px; border-bottom: 1px solid var(--line); margin-bottom: 8px; }
            .strip .axis span { position: absolute; bottom: 4px; transform: translateX(-50%); font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
            .strip .lane { position: relative; height: 34px; }
            .strip .lane .bar { position: absolute; top: 6px; height: 22px; border-radius: 6px; background: var(--brand); min-width: 8px; display: flex; align-items: center; padding: 0 8px; color: #fff; font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; }
            .strip .lane .bar.now { background: var(--gold); color: #1b1300; }
            .strip .lane .after { position: absolute; top: 9px; font-size: 13px; color: var(--muted); white-space: nowrap; }
            .facts { display: grid; gap: 16px; margin: 0; }
            .facts dt { font-size: 13px; color: var(--muted); margin-bottom: 1px; }
            .facts dd { margin: 0; font-weight: 500; }
            .verified-note { display: flex; gap: 12px; align-items: flex-start; }
            .verified-note svg { flex: none; color: var(--ok); margin-top: 2px; }
            .topics { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
            .topics span { padding: 3px 11px; border-radius: 999px; background: color-mix(in srgb, var(--brand) 10%, transparent); font-size: 14px; }
        </style>
    </x-slot:head>
    <x-slot:hero>
        <section class="hero">
            <div class="in">
                <div>
                    <h1>{{ $fullName }}</h1>
                    @if ($p->public_headline)<p class="headline">{{ $p->public_headline }}</p>@endif
                    <div class="tags">
                        @if ($programme)<span class="tag">{{ $programme->name }}</span>@endif
                        @if ($programme?->department?->school)<span class="tag">{{ $programme->department->school->name }}</span>@endif
                    </div>
                </div>
                @if ($p->graduation_year)
                    <div class="seal-wrap"><x-alumni.seal :year="$p->graduation_year" :size="220" /></div>
                @endif
            </div>
        </section>
    </x-slot:hero>

    <div class="layout">
        <div>
            @if ($strip)
                <section class="card" aria-labelledby="career">
                    <h2 id="career">Career at a glance</h2>
                    <div class="strip">
                        <div class="axis" aria-hidden="true">@foreach ($strip['years'] as $t)<span style="left: {{ $t['left'] }}%">{{ $t['year'] }}</span>@endforeach</div>
                        @foreach ($strip['rows'] as $r)
                            @php($label = $r['job']->employer.($r['job']->job_title ? ' · '.$r['job']->job_title : ''))
                            <div class="lane">
                                <div class="bar {{ $r['job']->is_current ? 'now' : '' }}" style="left: {{ $r['left'] }}%; width: {{ $r['width'] }}%" title="{{ $label }}">@if ($r['width'] > 22){{ $r['job']->employer }}@endif</div>
                                @if ($r['width'] <= 22)<span class="after" style="left: calc({{ $r['left'] + $r['width'] }}% + 8px)">{{ $r['job']->employer }}</span>@endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="card" aria-labelledby="journey">
                <h2 id="journey">Journey</h2>
                <ol class="journey">
                    @foreach ($jobs as $j)
                        <li class="{{ $j->is_current ? 'now' : '' }}">
                            <div class="when">{{ $span($j) }}</div>
                            <div class="what">{{ $j->job_title ?: $j->employer }}</div>
                            <div class="where">{{ $j->job_title ? $j->employer : '' }}{{ $j->city ? ($j->job_title ? ' · ' : '').$j->city : '' }}</div>
                        </li>
                    @endforeach
                    <li class="grad">
                        <div class="when">{{ $p->graduation_date?->format('F Y') ?? $p->graduation_year }}</div>
                        <div class="what">Graduated from Soroti University</div>
                        <div class="where">{{ $programme?->name }}</div>
                    </li>
                </ol>
            </section>
        </div>

        <aside>
            <section class="card">
                <div class="verified-note">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>
                    <div>
                        <strong>Graduation confirmed</strong>
                        <div class="muted small">Checked against Soroti University's Registrar records{{ $p->verified_at ? ' on '.$p->verified_at->format('j F Y') : '' }}.</div>
                    </div>
                </div>
            </section>

            @if ($location || $mentorTopics || $linkedin)
                <section class="card">
                    <dl class="facts">
                        @if ($location)<div><dt>Based in</dt><dd>{{ $location }}</dd></div>@endif
                        @if ($mentorTopics)<div><dt>Happy to mentor current students on</dt>
                            <dd><div class="topics">@foreach (array_filter(array_map('trim', explode(',', $mentorTopics))) as $t)<span>{{ $t }}</span>@endforeach</div></dd></div>@endif
                        @if ($linkedin)<div><dt>Elsewhere</dt><dd><a href="{{ $linkedin }}" rel="noopener nofollow ugc" target="_blank">LinkedIn profile</a></dd></div>@endif
                    </dl>
                </section>
            @endif

            <p class="muted small">This page was published by {{ $p->first_name }}, who chooses what appears here and can take it down at any time.</p>
        </aside>
    </div>
</x-layouts.public>

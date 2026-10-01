@php
    $s = $report['summary'];
    $min = $report['min'];
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $surveys = $report['filters']->source === 'surveys';
    // Segment class per outcome (colours live in admin.css as validated categorical slots).
    $legend = ['employed' => 'Employed', 'self_employed' => 'Self-employed', 'unemployed' => 'Seeking work', 'further_study' => 'Studying full time', 'other' => 'Other'];
@endphp
<div>
    <div class="head">
        <div>
            <h1>Graduate outcomes</h1>
            <span class="muted">Employment, further study and entrepreneurship of Soroti University graduates. {{ $report['source_label'] }}.</span>
        </div>
        <a class="btn secondary" href="{{ $exportUrl }}">Export CSV</a>
    </div>

    {{-- One filter row above everything it scopes: the tiles, the chart and the table all show the same slice. --}}
    <div class="panel">
        <div class="filters" style="margin-bottom:0">
            <label>Data from
                <select wire:model.live="source">
                    <option value="surveys">Tracer surveys</option>
                    <option value="profiles">Alumni profiles (latest status)</option>
                </select>
            </label>
            @if ($surveys)
                <label>Survey
                    <select wire:model.live="milestone">
                        <option value="">Latest answer from each alumnus</option>
                        <option value="6">6 months after graduation</option>
                        <option value="12">1 year after graduation</option>
                        <option value="36">3 years after graduation</option>
                    </select>
                </label>
            @endif
            <label>Compare by
                <select wire:model.live="groupBy">
                    @foreach ($groups as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label>School
                <select wire:model.live="schoolId">
                    <option value="">All schools</option>
                    @foreach ($schools as $school)<option value="{{ $school->id }}">{{ $school->name }}</option>@endforeach
                </select>
            </label>
            <label>Department
                <select wire:model.live="departmentId" @disabled(! $schoolId)>
                    <option value="">{{ $schoolId ? 'All departments' : 'Choose a school first' }}</option>
                    @foreach ($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach
                </select>
            </label>
            <label>Programme
                <select wire:model.live="programmeId">
                    <option value="">All programmes</option>
                    @foreach ($programmes as $programme)<option value="{{ $programme->id }}">{{ $programme->name }}</option>@endforeach
                </select>
            </label>
            <label>Graduated from
                <select wire:model.live="yearFrom">
                    <option value="">Any year</option>
                    @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                </select>
            </label>
            <label>to
                <select wire:model.live="yearTo">
                    <option value="">Any year</option>
                    @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                </select>
            </label>
            <button type="button" class="secondary" wire:click="clearFilters">Clear</button>
        </div>
    </div>

    {{-- While data refreshes the previous render stays put, dimmed, so nothing jumps. --}}
    <div class="viz" wire:loading.class="is-loading">
        @if ($s['n'] === 0)
            <div class="panel empty">
                No {{ $surveys ? 'survey responses' : 'recorded statuses' }} match this view yet.
                @if ($surveys)
                    Responses arrive as alumni answer the 6-month, 1-year and 3-year surveys. In the meantime, switch <strong>Data from</strong> to <em>Alumni profiles</em> to see what alumni have recorded themselves.
                @endif
            </div>
        @else
            {{-- Headline numbers are stat tiles, not charts. --}}
            <div class="stats-row">
                <div class="stat"><div class="v">{{ number_format($s['n']) }}</div><div class="l">{{ $surveys ? 'Graduates who answered' : 'Graduates with a known status' }}</div></div>
                <div class="stat"><div class="v">{{ $fmt($s['in_work_pct']) }}</div><div class="l">In work</div><div class="d">employed or self-employed</div></div>
                <div class="stat"><div class="v">{{ $fmt($s['pct']['unemployed']) }}</div><div class="l">Seeking work</div></div>
                <div class="stat"><div class="v">{{ $fmt($s['further_study_pct']) }}</div><div class="l">Further study</div><div class="d">studying now or planning to</div></div>
                <div class="stat">
                    <div class="v">{{ $fmt($s['business_pct']) }}</div><div class="l">Started a business</div>
                    <div class="d">{{ $surveys ? 'since graduating' : 'not recorded on profiles' }}</div>
                </div>
                <div class="stat">
                    <div class="v">{{ $fmt($s['rate_pct']) }}</div><div class="l">{{ $report['rate_label'] }}</div>
                    <div class="d">{{ number_format($s['rate_done']) }} of {{ number_format($s['rate_of']) }}</div>
                </div>
            </div>

            @if ($s['suppressed'])
                <div class="alert warn">Only {{ $s['n'] }} {{ Str::plural('graduate', $s['n']) }} in this view, which is fewer than {{ $min }}. To protect individuals, percentages are not shown for groups this small. Widen the view to see them.</div>
            @endif

            <div class="panel">
                <h2>Employment outcome by {{ strtolower($report['filters']->groupLabel()) }}</h2>
                <div class="legend" aria-label="Key">
                    @foreach ($legend as $key => $label)
                        <span><i class="seg {{ $key }}" style="width:12px;height:12px;min-width:0"></i>{{ $label }}</span>
                    @endforeach
                </div>

                <div class="bars">
                    @php($rowsToDraw = array_merge([$s], $report['rows']))
                    @foreach ($rowsToDraw as $i => $row)
                        <div class="name" @if ($i === 0) style="font-weight:600" @endif>{{ $row['label'] }}</div>
                        @if ($row['suppressed'])
                            <div class="small-n">Fewer than {{ $min }} {{ $surveys ? 'responses' : 'records' }}: not shown to protect privacy</div>
                        @else
                            <div class="bar {{ $i === 0 ? 'tall' : '' }}" role="img"
                                 aria-label="{{ $row['label'] }}: @foreach ($legend as $key => $label){{ $label }} {{ $fmt($row['pct'][$key]) }}@if (! $loop->last), @endif @endforeach">
                                @foreach ($legend as $key => $label)
                                    @if ($row['counts'][$key] > 0)
                                        <div class="seg {{ $key }}" tabindex="0" style="width:{{ $row['pct'][$key] }}%"
                                             data-tip="{{ $label }}: {{ $fmt($row['pct'][$key]) }} ({{ $row['counts'][$key] }} of {{ $row['n'] }})">
                                            {{-- Only label a segment that is wide enough to hold it, and only on the overall bar. --}}
                                            @if ($i === 0 && $row['pct'][$key] >= 9){{ round($row['pct'][$key]) }}%@endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                            <div class="count">{{ number_format($row['n']) }}</div>
                        @endif
                        @if ($i === 0)<hr class="rule">@endif
                    @endforeach
                </div>
                <p class="muted small" style="margin-bottom:0">Hover or tab to a segment for its exact figure; every figure is also in the table below.</p>
            </div>

            <div class="panel">
                <h2>Table view</h2>
                <div class="scroll-x">
                <table class="outcome-table">
                    <thead>
                    <tr>
                        <th>{{ $report['filters']->groupLabel() }}</th>
                        <th class="num">{{ $surveys ? 'Answered' : 'Known' }}</th>
                        <th class="num">In work</th>
                        @foreach ($legend as $key => $label)
                            <th class="num"><i class="key seg {{ $key }}" style="min-width:0"></i>{{ $label }}</th>
                        @endforeach
                        <th class="num">Further study</th>
                        <th class="num">Started a business</th>
                        <th class="num">{{ $surveys ? 'Response rate' : 'Coverage' }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rowsToDraw as $i => $row)
                        @if ($row['suppressed'])
                            <tr class="muted-row"><td>{{ $row['label'] }}</td><td colspan="{{ 9 }}">Fewer than {{ $min }} {{ $surveys ? 'responses' : 'records' }}: not shown</td></tr>
                        @else
                            <tr @if ($i === 0) style="font-weight:600" @endif>
                                <td>{{ $row['label'] }}</td>
                                <td class="num">{{ number_format($row['n']) }}</td>
                                <td class="num">{{ $fmt($row['in_work_pct']) }}</td>
                                @foreach ($legend as $key => $label)
                                    <td class="num">{{ $fmt($row['pct'][$key]) }}</td>
                                @endforeach
                                <td class="num">{{ $fmt($row['further_study_pct']) }}</td>
                                <td class="num">{{ $fmt($row['business_pct']) }}</td>
                                <td class="num">{{ $fmt($row['rate_pct']) }}</td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>

            <p class="muted small">
                @if ($surveys)
                    Based on what graduates told us in the tracer surveys. “Response rate” is the share of surveys that have <em>closed</em> that were answered; surveys still open are not counted as missed.
                @else
                    Based on the latest status graduates recorded on their own profile at any time, which is broader but less rigorous than a survey. “Coverage” is how many graduate records have a status.
                @endif
                Groups with fewer than {{ $min }} {{ $surveys ? 'responses' : 'records' }} are hidden. Percentages are of everyone in the row.
            </p>
        @endif
    </div>
</div>

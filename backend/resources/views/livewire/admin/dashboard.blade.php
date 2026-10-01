<div>
    <div class="head">
        <div>
            <h1>Dashboard</h1>
            <span class="muted">Where the alumni directory stands today.</span>
        </div>
    </div>

    <div class="cards">
        <div class="card"><div class="n">{{ number_format($total) }}</div><div class="l">Alumni records</div></div>
        <div class="card"><div class="n">{{ number_format($verified) }}</div><div class="l">Registered &amp; verified</div></div>
        <div class="card"><div class="n">{{ number_format($unclaimed) }}</div><div class="l">Not yet registered</div></div>
        <div class="card">
            <div class="n">{{ number_format($pending) }}</div>
            <div class="l">Awaiting your review</div>
            @if ($pending && auth()->user()->canManageRecords())
                <a class="small" href="{{ route('admin.verification') }}">Review now →</a>
            @endif
        </div>
        <div class="card"><div class="n">{{ number_format($fresh) }}</div><div class="l">Updated by alumni in last 12 months</div></div>
        <div class="card">
            <div class="n">{{ $surveysSent > 0 ? round($surveysDone / $surveysSent * 100).'%' : '—' }}</div>
            <div class="l">Tracer survey response rate</div>
            <a class="small" href="{{ route('admin.surveys') }}">{{ number_format($surveysDone) }} of {{ number_format($surveysSent) }} →</a>
        </div>
        <div class="card">
            <div class="n">{{ number_format($taCandidates) }}</div>
            <div class="l">Teaching-assistant candidates</div>
            <a class="small" href="{{ route('admin.alumni.index', ['taOnly' => 1]) }}">View them →</a>
        </div>
    </div>

    <div class="panel">
        <h2>Records by graduation year</h2>
        @forelse ($byYear as $row)
            <div style="display:grid;grid-template-columns:60px 1fr 60px;gap:10px;align-items:center;margin-bottom:6px">
                <span>{{ $row->graduation_year }}</span>
                <div style="background:var(--brand);height:12px;border-radius:3px;width:{{ max(2, round($row->n / max(1, $byYear->max('n')) * 100)) }}%"></div>
                <span class="muted">{{ number_format($row->n) }}</span>
            </div>
        @empty
            <div class="empty">
                No records yet.
                @if (auth()->user()->canManageRecords())
                    <a href="{{ route('admin.import') }}">Import the Registrar's spreadsheet</a> to get started.
                @endif
            </div>
        @endforelse
    </div>
</div>

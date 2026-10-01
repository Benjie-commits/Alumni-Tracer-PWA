<div>
    <div class="head">
        <div>
            <a href="{{ route('admin.surveys') }}" class="small">← Tracer surveys</a>
            <h1>{{ $cycle->title }}: responses</h1>
            <span class="muted">{{ number_format($responses->total()) }} {{ Str::plural('response', $responses->total()) }}. These are personal answers: handle them as you would the alumni records.</span>
        </div>
        <a class="btn secondary" href="{{ route('admin.surveys.export', $cycle) }}">Export CSV</a>
    </div>

    <div class="panel">
        <table>
            <thead>
            <tr><th>Alumnus</th><th>Programme</th><th>Graduated</th><th>Doing</th><th>Answered</th><th></th></tr>
            </thead>
            <tbody>
            @forelse ($responses as $response)
                <tr wire:key="r{{ $response->id }}">
                    <td>
                        <a href="{{ route('admin.alumni.show', $response->profile) }}">{{ $response->profile->last_name }}, {{ $response->profile->first_name }}</a>
                        @if ($response->profile->ta_flagged_at)<span class="pill" title="Teaching-assistant candidate">TA</span>@endif
                    </td>
                    <td>{{ $response->profile->programme?->name ?? '—' }}</td>
                    <td>{{ $response->profile->graduation_year ?? '—' }}</td>
                    <td>{{ $response->employment_status ? \App\Enums\EmploymentStatus::from($response->employment_status)->label() : '—' }}</td>
                    <td class="muted small">{{ $response->submitted_at->format('j M Y') }}</td>
                    <td><button type="button" class="secondary" wire:click="toggle({{ $response->id }})">{{ $openId === $response->id ? 'Hide' : 'View answers' }}</button></td>
                </tr>
                @if ($openId === $response->id)
                    <tr wire:key="a{{ $response->id }}">
                        <td colspan="6" style="background:var(--bg)">
                            <dl style="margin:0;display:grid;grid-template-columns:minmax(220px,1fr) 2fr;gap:6px 18px">
                                @foreach ($openAnswers as $row)
                                    <dt class="muted">{{ $row['question'] }}</dt>
                                    <dd style="margin:0">{{ $row['answer'] }}</dd>
                                @endforeach
                            </dl>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="6" class="empty">No one has answered this survey yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        {{ $responses->links() }}
    </div>
</div>

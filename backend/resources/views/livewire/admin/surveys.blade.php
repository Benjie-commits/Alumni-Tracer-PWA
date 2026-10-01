<div>
    <div class="head">
        <div>
            <h1>Tracer surveys</h1>
            <span class="muted">Alumni are surveyed automatically 6 months, 1 year and 3 years after graduating. Invitations go out each morning.</span>
        </div>
    </div>

    @foreach ($rows as $row)
        @php($cycle = $row['cycle'])
        <div class="panel" wire:key="cycle{{ $cycle->id }}">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px">
                <div>
                    <h2 style="margin-bottom:2px">{{ $cycle->title }}
                        <span class="pill {{ $cycle->is_active ? 'verified' : 'rejected' }}">{{ $cycle->is_active ? 'Running' : 'Paused' }}</span>
                    </h2>
                    <span class="muted small">
                        Asks about {{ $cycle->periodLabel() }} after graduation · open for {{ $cycle->windowDays() }} days ·
                        questionnaire version {{ $cycle->currentVersion?->version ?? '—' }}
                    </span>
                </div>
                @if ($canManage)
                    <div style="white-space:nowrap">
                        <a class="btn secondary" href="{{ route('admin.surveys.responses', $cycle) }}">Responses</a>
                        <button type="button" class="secondary" wire:click="toggleActive({{ $cycle->id }})"
                                wire:confirm="{{ $cycle->is_active ? 'Pause this survey? No new invitations will be created until you resume it.' : 'Resume this survey?' }}">
                            {{ $cycle->is_active ? 'Pause' : 'Resume' }}
                        </button>
                    </div>
                @endif
            </div>

            <div class="cards" style="margin-top:14px">
                <div class="card"><div class="n">{{ number_format($row['total']) }}</div><div class="l">Invited so far</div></div>
                <div class="card"><div class="n">{{ number_format($row['completed']) }}</div><div class="l">Completed</div></div>
                <div class="card"><div class="n">{{ $row['rate'] === null ? '—' : $row['rate'].'%' }}</div><div class="l">Answered so far</div></div>
                <div class="card"><div class="n">{{ number_format($row['sent'] + $row['scheduled']) }}</div><div class="l">Still open</div></div>
                <div class="card"><div class="n">{{ number_format($row['expired']) }}</div><div class="l">Expired unanswered</div></div>
                <div class="card"><div class="n">{{ number_format($row['dueToday']) }}</div><div class="l">Due for an invitation today</div></div>
                <div class="card"><div class="n">{{ number_format($row['upcoming']) }}</div><div class="l">Reaching this milestone in 30 days</div></div>
            </div>

            <details>
                <summary>Preview the questions ({{ count($cycle->currentVersion?->questions() ?? []) }})</summary>
                <ol style="margin-top:10px">
                    @foreach ($cycle->currentVersion?->questions() ?? [] as $question)
                        <li style="margin-bottom:6px">
                            {{ $question['label'] }}
                            <span class="muted small">
                                ({{ str_replace('_', ' ', $question['type']) }}{{ ($question['required'] ?? false) ? ', required' : '' }}{{ isset($question['show_if']) ? ', only some people' : '' }})
                            </span>
                            @if (in_array($question['type'], ['single_choice', 'multi_choice'], true))
                                <div class="muted small">{{ collect($question['options'])->pluck('label')->implode(' · ') }}</div>
                            @endif
                        </li>
                    @endforeach
                </ol>
                <p class="muted small">To change the wording, edit <code>config/tracer_surveys.php</code> and run <code>php artisan sunates:sync-surveys</code>. Each change becomes a new version; answers already collected keep the wording they were given for.</p>
            </details>
        </div>
    @endforeach
</div>

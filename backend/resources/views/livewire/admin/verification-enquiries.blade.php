<div>
    <div class="head">
        <div>
            <h1>Verification enquiries</h1>
            <span class="muted">Employers and partners check graduates at <code>{{ url('/verify') }}</code>. When a lookup finds nobody they can ask you to check by hand; those requests land here.</span>
        </div>
    </div>

    <div class="cards">
        <div class="card"><div class="n">{{ number_format($counts['verified'] + $counts['not_found'] + $counts['ambiguous']) }}</div><div class="l">Lookups, last 30 days</div></div>
        <div class="card"><div class="n">{{ number_format($counts['verified']) }}</div><div class="l">Verified automatically</div></div>
        <div class="card"><div class="n">{{ number_format($counts['not_found'] + $counts['ambiguous']) }}</div><div class="l">Not found or unclear</div></div>
        <div class="card"><div class="n">{{ number_format($openCount) }}</div><div class="l">Waiting for you</div></div>
    </div>

    <div class="filters" style="margin-bottom:0">
        <button type="button" class="{{ $tab === 'open' ? '' : 'secondary' }}" wire:click="$set('tab', 'open')">Waiting ({{ $openCount }})</button>
        <button type="button" class="{{ $tab === 'resolved' ? '' : 'secondary' }}" wire:click="$set('tab', 'resolved')">Resolved</button>
        <button type="button" class="{{ $tab === 'log' ? '' : 'secondary' }}" wire:click="$set('tab', 'log')">Lookup log</button>
    </div>

    @if ($tab !== 'log')
        @forelse ($enquiries as $e)
            <div class="panel" wire:key="e{{ $e->id }}" style="margin-top:14px">
                <div style="display:flex;justify-content:space-between;gap:20px">
                    <div>
                        <h2 style="margin-bottom:2px">Asked about: {{ $e->subject_name }}</h2>
                        <div class="muted small">
                            {{ $e->subject_programme ?? 'any programme' }} · {{ $e->subject_graduation_year ?? 'any year' }}
                            · lookup said <strong>{{ $e->lookup_result->label() }}</strong> · ref {{ $e->request_reference }}
                            · {{ $e->created_at->diffForHumans() }}
                        </div>
                    </div>
                    @if ($e->status === \App\Enums\EscalationStatus::Resolved)
                        <span class="pill verified" style="height:fit-content">Resolved</span>
                    @endif
                </div>

                <p style="margin:12px 0 4px"><strong>{{ $e->requester_name }}</strong>, {{ $e->organisation }}</p>
                <p class="small" style="margin:0">
                    <a href="mailto:{{ $e->requester_email }}">{{ $e->requester_email }}</a>
                    @if ($e->requester_phone) · {{ $e->requester_phone }} @endif
                </p>
                @if ($e->message)<p style="margin:10px 0 0;white-space:pre-line">{{ $e->message }}</p>@endif

                @if ($e->status === \App\Enums\EscalationStatus::Open)
                    <div class="filters" style="margin:14px 0 0">
                        <label style="flex:1">Note for the record (optional)
                            <input type="text" wire:model="notes.{{ $e->id }}" maxlength="1000" placeholder="e.g. Confirmed by email on 3 Oct: graduated 2022, BSc Biology">
                            @error("notes.{$e->id}")<span class="error">{{ $message }}</span>@enderror
                        </label>
                        <button type="button" wire:click="resolve({{ $e->id }})" wire:confirm="Mark this enquiry as dealt with? Reply to the requester from your own email first.">Mark as dealt with</button>
                    </div>
                @else
                    <p class="muted small" style="margin:12px 0 0">
                        Resolved {{ $e->resolved_at?->diffForHumans() }} by {{ $e->resolver?->name ?? 'a staff member' }}
                        @if ($e->resolution_note) · “{{ $e->resolution_note }}” @endif
                    </p>
                @endif
            </div>
        @empty
            <div class="panel empty" style="margin-top:14px">{{ $tab === 'open' ? 'Nothing waiting. 🎓' : 'No resolved enquiries yet.' }}</div>
        @endforelse

        {{ $enquiries->links() }}
    @else
        <div class="panel" style="margin-top:14px">
            <p class="muted small" style="margin-top:0">Every lookup, including the ones that found nothing. Kept for {{ config('sunates.verification.log_retention_days') }} days, then deleted. Stored apart from alumni profile data.</p>
            <table>
                <thead><tr><th>When</th><th>Organisation</th><th>Asked about</th><th>How</th><th>Result</th><th>Ref</th></tr></thead>
                <tbody>
                @forelse ($lookups as $l)
                    <tr wire:key="l{{ $l->id }}">
                        <td class="muted small" style="white-space:nowrap">{{ $l->created_at->format('j M, H:i') }}</td>
                        <td>{{ $l->organisation ?? '—' }}<div class="muted small">{{ $l->requester_email }}</div></td>
                        <td>{{ $l->query_name ?? 'via shared link' }}@if ($l->query_graduation_year)<div class="muted small">{{ $l->query_graduation_year }}</div>@endif</td>
                        <td>{{ $l->channel->label() }}</td>
                        <td><span class="pill {{ $l->result === \App\Enums\VerificationResult::Verified ? 'verified' : 'pending' }}">{{ $l->result->label() }}</span></td>
                        <td class="small">{{ $l->reference }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No lookups yet.</td></tr>
                @endforelse
                </tbody>
            </table>
            {{ $lookups->links() }}
        </div>
    @endif
</div>

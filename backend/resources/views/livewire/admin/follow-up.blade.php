<div>
    <div class="head">
        <div>
            <h1>Follow-up list</h1>
            <span class="muted">Graduates who have not confirmed their details in over a year and have not answered our messages. Here you can look for them by hand and record what you find.</span>
        </div>
    </div>

    <div class="panel">
        <h2>How this works</h2>
        <p class="small" style="margin-top:0">
            LinkedIn does not allow a school to watch its graduates' profiles, so this list is the careful alternative:
            <strong>a person, looking up one graduate at a time, on LinkedIn's own website.</strong>
            Please do not use scraping tools or browser extensions; they break LinkedIn's terms and put the university's account at risk.
        </p>
        <ul class="small" style="margin:0 0 0 18px;padding:0">
            <li>Only graduates the Registrar's records confirm are listed, and not anyone who asked us to stop all messages.</li>
            <li>When you have looked, record the result. They will not be suggested again for {{ config('sunates.followup.recheck_after_days') }} days.</li>
            <li>If you find them, open their record and update what is out of date. Keep the note short (for example “now at Stanbic Bank, Kampala”); do not copy their profile.</li>
            <li>Their record is still marked as <em>not confirmed by the alumnus</em>, because they did not tell us themselves. We keep asking them.</li>
        </ul>
    </div>

    @if ($recorded)<div class="alert ok">{{ $recorded }}</div>@endif

    <div class="filters" style="margin-bottom:0">
        <button type="button" class="{{ $tab === 'unresponsive' ? '' : 'secondary' }}" wire:click="$set('tab', 'unresponsive')">Did not respond ({{ number_format($unresponsiveCount) }})</button>
        <button type="button" class="{{ $tab === 'unreachable' ? '' : 'secondary' }}" wire:click="$set('tab', 'unreachable')">No phone number ({{ number_format($unreachableCount) }})</button>
    </div>
    <p class="muted small" style="margin:8px 0 14px">
        @if ($tab === 'unresponsive')
            We messaged them at least {{ config('sunates.followup.min_nudges') }} times in the last year and heard nothing back.
        @else
            We hold no phone or WhatsApp number for them, so we have not been able to ask. These are the people a search is most likely to help.
        @endif
    </p>

    <div class="filters">
        <label>Search name or student number
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Name or student number">
        </label>
        <label>Programme
            <select wire:model.live="programme">
                <option value="">All programmes</option>
                @foreach ($programmes as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
        </label>
        <label>Graduated
            <select wire:model.live="year">
                <option value="">Any year</option>
                @foreach ($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
            </select>
        </label>
    </div>

    <div class="panel">
        <div class="scroll-x">
            <table>
                <thead>
                <tr><th>Graduate</th><th>Last confirmed</th><th>Where to look</th><th style="min-width:300px">What did you find?</th></tr>
                </thead>
                <tbody>
                @forelse ($people as $person)
                    <tr wire:key="p{{ $person->id }}">
                        <td>
                            <a href="{{ route('admin.alumni.show', $person) }}">{{ $person->full_name }}</a>
                            <div class="muted small">{{ $person->programme?->name ?? 'Programme unknown' }} · {{ $person->graduation_year }}</div>
                            @if ($tab === 'unresponsive')
                                <div class="muted small">{{ $person->nudges_sent }} {{ \Illuminate\Support\Str::plural('message', $person->nudges_sent) }} sent, no reply</div>
                            @endif
                        </td>
                        <td class="small" style="white-space:nowrap">{{ $person->profile_updated_at?->format('j M Y') ?? 'Never' }}</td>
                        <td class="small">
                            @if ($person->linkedin_url)
                                <a href="{{ $person->linkedin_url }}" target="_blank" rel="noopener noreferrer" style="white-space:nowrap">Their LinkedIn profile ↗</a>
                                <div class="muted">given by the graduate</div>
                            @else
                                <a href="https://www.linkedin.com/search/results/people/?keywords={{ rawurlencode($person->full_name.' Soroti University') }}" target="_blank" rel="noopener noreferrer" style="white-space:nowrap">Search LinkedIn ↗</a>
                                <div class="muted">by name and university</div>
                            @endif
                        </td>
                        <td>
                            <select wire:model="outcome.{{ $person->id }}" aria-label="What you found for {{ $person->full_name }}">
                                <option value="">Choose…</option>
                                @foreach ($outcomes as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach
                            </select>
                            <input type="text" wire:model="note.{{ $person->id }}" maxlength="500" placeholder="Short note (optional)" style="margin-top:6px;width:100%" aria-label="Note for {{ $person->full_name }}">
                            @error("outcome.{$person->id}")<div class="error">{{ $message }}</div>@enderror
                            @error("note.{$person->id}")<div class="error">{{ $message }}</div>@enderror
                            <button type="button" class="secondary" style="margin-top:6px" wire:click="record({{ $person->id }})">Record</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">
                        {{ $tab === 'unresponsive' ? 'Nobody is waiting here. People appear once they have been messaged repeatedly without answering.' : 'Nobody without a phone number needs a search right now.' }}
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $people->links() }}
    </div>
</div>

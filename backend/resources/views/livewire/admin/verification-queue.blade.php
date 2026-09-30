<div>
    <div class="head">
        <div>
            <h1>Verification queue</h1>
            <span class="muted">Alumni who registered but did not match a Registrar record. Check each claim, then link it to the right record, approve it as new, or reject it.</span>
        </div>
    </div>

    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    @forelse ($queue as $profile)
        <div class="panel" wire:key="q{{ $profile->id }}">
            <div style="display:flex;justify-content:space-between;gap:20px">
                <div>
                    <h2 style="margin-bottom:2px">{{ $profile->full_name }}</h2>
                    <div class="muted small">
                        Claimed student no. <strong>{{ $profile->declared_student_number ?? '—' }}</strong>
                        · graduated {{ $profile->graduation_year ?? '—' }}
                        · {{ $profile->programme?->name ?? 'programme not given' }}
                    </div>
                    <div class="muted small">{{ $profile->email }} {{ $profile->phone ? '· '.$profile->phone : '' }} · registered {{ $profile->claimed_at?->diffForHumans() }}</div>
                </div>
                <div style="white-space:nowrap">
                    <button type="button" wire:click="approve({{ $profile->id }})" wire:confirm="Approve {{ $profile->full_name }} as a new alumni record with no Registrar match?">Approve as new</button>
                    <button type="button" class="danger" wire:click="reject({{ $profile->id }})" wire:confirm="Reject this claim?">Reject</button>
                </div>
            </div>

            <h2 style="margin-top:16px;font-size:13px">Possible Registrar matches</h2>
            @if ($candidates[$profile->id]->isEmpty())
                <div class="muted small">No unclaimed record shares this surname or student number.</div>
            @else
                <table>
                    <thead><tr><th>Name</th><th>Student no.</th><th>Programme</th><th>Year</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($candidates[$profile->id] as $record)
                        <tr wire:key="c{{ $profile->id }}-{{ $record->id }}">
                            <td>{{ $record->full_name }}</td>
                            <td>{{ $record->student_number }}</td>
                            <td>{{ $record->programme?->name ?? '—' }}</td>
                            <td>{{ $record->graduation_year ?? '—' }}</td>
                            <td><button type="button" class="secondary" wire:click="link({{ $profile->id }}, {{ $record->id }})" wire:confirm="Link {{ $profile->full_name }}'s account to {{ $record->full_name }} ({{ $record->student_number }})?">This is them</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        <div class="panel empty">Nothing waiting for review. 🎓</div>
    @endforelse
</div>

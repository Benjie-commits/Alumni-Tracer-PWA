<div>
    <div class="head">
        <div>
            <h1>Activity log</h1>
            <span class="muted">Sensitive staff actions: record edits, verification decisions, imports, exports and account changes.
                It lists which fields changed, never their values. Entries are deleted after {{ number_format($retentionDays) }} days.</span>
        </div>
    </div>

    <div class="panel">
        <div class="filters">
            <label>Action
                <select wire:model.live="action">
                    <option value="">Any</option>
                    @foreach ($labels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label>Staff member
                <select wire:model.live="staff">
                    <option value="">Anyone</option>
                    @foreach ($staffUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                </select>
            </label>
        </div>

        <table>
            <thead><tr><th>When</th><th>Who</th><th>What</th><th>Record</th><th>Fields changed</th><th>Detail</th><th>From</th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr wire:key="a{{ $log->id }}">
                    <td class="muted small" style="white-space:nowrap">{{ $log->created_at->format('j M Y, H:i') }}</td>
                    <td>{{ $log->user?->name ?? 'System / deleted account' }}</td>
                    <td>{{ $log->label() }}</td>
                    <td class="small">
                        @if ($log->subject_type === 'AlumniProfile' && $log->subject_id)
                            <a href="{{ route('admin.alumni.show', $log->subject_id) }}">Alumni record #{{ $log->subject_id }}</a>
                        @elseif ($log->subject_type)
                            {{ $log->subject_type }} #{{ $log->subject_id }}
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td class="small">{{ $log->changed_fields ? implode(', ', $log->changed_fields) : '—' }}</td>
                    <td class="small muted">
                        @foreach ((array) $log->meta as $key => $value)
                            {{ str_replace('_', ' ', $key) }}: {{ is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'yes' : 'no') : $value) }}@if (! $loop->last); @endif
                        @endforeach
                    </td>
                    <td class="small muted">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Nothing recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        {{ $logs->links() }}
    </div>
</div>

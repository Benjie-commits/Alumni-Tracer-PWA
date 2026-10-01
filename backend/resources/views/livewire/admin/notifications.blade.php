<div>
    <div class="head">
        <div>
            <h1>Messages</h1>
            <span class="muted">Every SMS and WhatsApp message the system has tried to send. The text itself is not kept, because survey messages contain a private link.</span>
        </div>
    </div>

    @unless ($driversAreLive)
        <div class="alert warn">
            Messaging is in <strong>log-only mode</strong>: nothing is actually being sent (<code>SMS_DRIVER=log</code>, <code>WHATSAPP_DRIVER=log</code>).
            Messages appear below as if sent, but go to the server log only. Set the real drivers in <code>.env</code> to go live.
        </div>
    @endunless

    <div class="cards">
        <div class="card"><div class="n">{{ number_format($counts['sent'] + $counts['delivered'] + $counts['read']) }}</div><div class="l">Sent, last 30 days</div></div>
        <div class="card"><div class="n">{{ number_format($counts['delivered'] + $counts['read']) }}</div><div class="l">Confirmed delivered</div></div>
        <div class="card"><div class="n">{{ number_format($counts['failed']) }}</div><div class="l">Failed</div></div>
        <div class="card"><div class="n">{{ number_format($counts['blocked']) }}</div><div class="l">Not sent (opted out / no number)</div></div>
    </div>

    @if ($isIct)
        <form class="panel" wire:submit="sendTest">
            <h2>Send a test message</h2>
            <p class="muted small">Use your own number to check the MTN SMS or WhatsApp credentials work. It sends one short test text, not a survey.</p>
            <div class="filters">
                <label>Channel
                    <select wire:model="testChannel">
                        <option value="sms">SMS (MTN)</option>
                        <option value="whatsapp">WhatsApp</option>
                    </select>
                </label>
                <label>Phone number
                    <input type="text" wire:model="testNumber" placeholder="+256700000000">
                    @error('testNumber')<span class="error">{{ $message }}</span>@enderror
                </label>
                <button type="submit" wire:loading.attr="disabled" wire:target="sendTest">Send test</button>
            </div>
            @if ($testResult)
                <div class="alert {{ $testFailed ? 'bad' : 'ok' }}" style="margin:0">{{ $testResult }}</div>
            @endif
        </form>
    @endif

    <div class="panel">
        <div class="filters">
            <label>Status
                <select wire:model.live="status">
                    <option value="">Any</option>
                    @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                </select>
            </label>
            <label>Channel
                <select wire:model.live="channel">
                    <option value="">Any</option>
                    @foreach ($channels as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach
                </select>
            </label>
            <label>Message
                <select wire:model.live="template">
                    <option value="">Any</option>
                    @foreach ($templates as $t)<option value="{{ $t->value }}">{{ str_replace('_', ' ', ucfirst($t->value)) }}</option>@endforeach
                </select>
            </label>
        </div>

        <table>
            <thead><tr><th>When</th><th>Alumnus</th><th>Message</th><th>Channel</th><th>To</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
            @forelse ($logs as $log)
                <tr wire:key="n{{ $log->id }}">
                    <td class="muted small" style="white-space:nowrap">{{ $log->created_at->format('j M, H:i') }}</td>
                    <td>
                        @if ($log->profile)
                            <a href="{{ route('admin.alumni.show', $log->profile) }}">{{ $log->profile->last_name }}, {{ $log->profile->first_name }}</a>
                        @else
                            <span class="muted">{{ $log->template === \App\Enums\MessageTemplate::Test ? 'Test message' : '—' }}</span>
                        @endif
                    </td>
                    <td>{{ str_replace('_', ' ', ucfirst($log->template->value)) }}</td>
                    <td>{{ $log->channel?->label() ?? '—' }}</td>
                    <td class="small">{{ $log->maskedNumber() }}</td>
                    <td>
                        <span class="pill {{ in_array($log->status->value, ['delivered', 'read'], true) ? 'verified' : ($log->status === \App\Enums\NotificationStatus::Failed ? 'rejected' : ($log->status === \App\Enums\NotificationStatus::Blocked ? 'pending' : '')) }}">{{ $log->status->label() }}</span>
                    </td>
                    <td class="small muted">{{ $log->error }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No messages match.</td></tr>
            @endforelse
            </tbody>
        </table>

        {{ $logs->links() }}
    </div>
</div>

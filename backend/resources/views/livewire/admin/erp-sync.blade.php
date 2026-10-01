<div @if ($busy) wire:poll.3s @endif>
    <div class="head">
        <div>
            <h1>SorotiUniERP sync</h1>
            <span class="muted">When a student graduates in the university's ERP, this system creates their alumni record on its own, by the same rules as a spreadsheet import, so nobody types it twice.</span>
        </div>
    </div>

    <div class="panel">
        <div class="grid2">
            <div>
                <div class="muted small">Connection</div>
                @if ($enabled)
                    <div><span class="pill verified">On</span> {{ $target }}</div>
                @else
                    <div><span class="pill">Off · standalone mode</span></div>
                @endif
            </div>
            <div>
                <div class="muted small">Last successful sync</div>
                <div>
                    @if ($last)
                        {{ $last->finished_at?->timezone(config('sunates.timezone'))->format('j M Y, H:i') }}
                        <span class="muted small">· {{ $last->created }} new, {{ $last->updated }} updated</span>
                    @else
                        <span class="muted">None yet</span>
                    @endif
                </div>
            </div>
            @if ($enabled)
                <div>
                    <div class="muted small">Runs automatically</div>
                    <div>Every day at {{ config('sunates.erp.sync_at') }} (Uganda time)</div>
                </div>
                <div>
                    <div class="muted small">Next sync picks up changes from</div>
                    <div>
                        @if ($since = \App\Models\ErpSyncRun::lastCursor())
                            {{ $since->copy()->subHours((int) config('sunates.erp.overlap_hours'))->timezone(config('sunates.timezone'))->format('j M Y, H:i') }}
                        @else
                            <span class="muted">the beginning (everything)</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        @unless ($enabled)
            <p class="muted small" style="margin-bottom:0">
                Until SorotiUniERP is in active use, alumni come from the Registrar's spreadsheets (<a href="{{ route('admin.import') }}">Import from spreadsheet</a>).
                To switch this on, the Directorate of ICT sets <code>ERP_DRIVER</code> to <code>rest</code> or <code>database</code> and fills in the matching settings in the server's <code>.env</code> file; the README lists every setting.
            </p>
        @endunless
    </div>

    @if ($enabled && $canRun)
        <div class="panel">
            <h2>Run it now</h2>
            <p class="muted small" style="margin-top:0">Test the connection first. A preview reads the ERP and shows what would change without saving anything.</p>

            <div class="actions" style="margin-top:0">
                <button type="button" class="secondary" wire:click="check" wire:loading.attr="disabled" wire:target="check">Test the connection</button>
                <button type="button" class="secondary" wire:click="preview" wire:loading.attr="disabled" wire:target="preview,syncNow,syncEverything" @disabled($busy)>Preview (nothing is saved)</button>
                <button type="button" wire:click="syncNow" wire:loading.attr="disabled" wire:target="preview,syncNow,syncEverything" @disabled($busy)
                        wire:confirm="Read recent changes from the ERP and save them as alumni records now?">Sync now</button>
                <button type="button" class="secondary" wire:click="syncEverything" wire:loading.attr="disabled" wire:target="preview,syncNow,syncEverything" @disabled($busy)
                        wire:confirm="Read every graduate in the ERP, not just recent changes? This can take a while.">Re-read everything</button>
                <span class="muted small" wire:loading wire:target="check">Contacting the ERP…</span>
            </div>

            @error('sync')<div class="alert bad" style="margin:14px 0 0">{{ $message }}</div>@enderror
            @if ($started)<div class="alert ok" style="margin:14px 0 0">{{ $started }}</div>@endif
            @if ($checkMessage !== null)
                <div class="alert {{ $checkOk ? 'ok' : 'bad' }}" style="margin:14px 0 0">{{ $checkMessage }}</div>
            @endif
        </div>
    @elseif ($enabled)
        <p class="muted small">Only the Directorate of ICT can start a sync. You can see what each one did below.</p>
    @endif

    <div class="panel">
        <h2>Recent syncs</h2>
        <div class="scroll-x">
            <table>
                <thead>
                <tr>
                    <th>When</th><th>Kind</th><th>Result</th>
                    <th class="num">Read</th><th class="num">New</th><th class="num">Updated</th><th class="num">Same</th><th class="num">Not yet graduated</th><th class="num">Rejected</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($runs as $run)
                    <tr wire:key="run{{ $run->id }}">
                        <td style="white-space:nowrap">
                            {{ $run->created_at->timezone(config('sunates.timezone'))->format('j M, H:i') }}
                            <div class="muted small">{{ $run->trigger === 'scheduled' ? 'automatic' : ($run->starter?->name ?? 'command line') }}</div>
                        </td>
                        <td>
                            {{ $run->dry_run ? 'Preview' : 'Live' }}
                            <div class="muted small">
                                @if ($run->full)
                                    everything, as asked
                                @elseif ($run->since)
                                    changes since {{ $run->since->timezone(config('sunates.timezone'))->format('j M, H:i') }}
                                @elseif ($run->status !== \App\Enums\ErpSyncStatus::Queued)
                                    everything (no earlier sync to build on)
                                @endif
                            </div>
                        </td>
                        <td>
                            @if ($run->status === \App\Enums\ErpSyncStatus::Failed)
                                <span class="pill rejected">Failed</span>
                            @elseif ($run->status->isActive())
                                <span class="pill pending">{{ $run->status->label() }}</span>
                            @elseif ($run->rejected > 0)
                                <span class="pill pending">Finished with problems</span>
                            @else
                                <span class="pill verified">Finished</span>
                            @endif
                        </td>
                        @if ($run->status === \App\Enums\ErpSyncStatus::Succeeded)
                            <td class="num">{{ number_format($run->fetched) }}</td>
                            <td class="num">{{ number_format($run->created) }}</td>
                            <td class="num">{{ number_format($run->updated) }}</td>
                            <td class="num">{{ number_format($run->unchanged) }}</td>
                            <td class="num">{{ number_format($run->not_graduated) }}</td>
                            <td class="num">
                                @if ($run->rejected > 0 || ! empty($run->report['warnings']))
                                    <button type="button" class="secondary" style="padding:1px 8px" wire:click="toggle({{ $run->id }})">{{ number_format($run->rejected) }} ▾</button>
                                @else
                                    0
                                @endif
                            </td>
                        @else
                            <td colspan="6" class="muted small">{{ $run->error ?? 'Working…' }}</td>
                        @endif
                    </tr>
                    @if ($openRun === $run->id)
                        <tr wire:key="detail{{ $run->id }}">
                            <td colspan="9" style="background:var(--bg)">
                                @if ($run->rejected > 0)
                                    <strong>Turned away (fix these in the ERP; they are offered again next time)</strong>
                                    <ul class="small" style="margin:6px 0 12px">
                                        @foreach ($run->report['errors'] ?? [] as $item)<li>{{ $item['message'] }}</li>@endforeach
                                    </ul>
                                @endif
                                @if (! empty($run->report['warnings']))
                                    <strong>Saved, but worth a look</strong>
                                    <ul class="small" style="margin:6px 0 0">
                                        @foreach ($run->report['warnings'] as $item)<li>{{ $item['message'] }}</li>@endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="9" class="empty">No syncs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

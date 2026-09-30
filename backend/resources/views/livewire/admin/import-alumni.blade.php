<div>
    <div class="head">
        <div>
            <h1>Import from spreadsheet</h1>
            <span class="muted">Load or refresh alumni records from the Registrar's graduate lists. Always preview first: nothing is saved until you confirm.</span>
        </div>
    </div>

    <div class="panel">
        <h2>1. Choose a CSV file</h2>
        <p class="muted small">
            Required columns: <strong>Student number, First name, Surname</strong>.
            Optional: Other names, Gender, School, Department, Programme, Graduation year, Graduation date (dd/mm/yyyy), Class of award, Date of birth, Email, Phone.
            Column names are matched loosely (e.g. "Reg No", "Faculty", "Course"). Rows are matched on student number, so importing the same list twice is safe.
        </p>
        <input type="file" wire:model="file" accept=".csv,.txt">
        <div wire:loading wire:target="file" class="muted small">Uploading…</div>
        @error('file')<div class="error">{{ $message }}</div>@enderror

        <div class="actions">
            <button type="button" wire:click="preview" wire:loading.attr="disabled" wire:target="preview,commit" @disabled(! $file)>Preview (nothing is saved)</button>
        </div>
    </div>

    @if ($report)
        <div class="panel">
            <h2>{{ $report['dry_run'] ? '2. Preview' : 'Import complete' }}</h2>

            @if ($report['dry_run'])
                <div class="alert warn">This is a preview. No records have been saved yet.</div>
            @else
                <div class="alert ok">Records saved.</div>
            @endif

            <div class="cards">
                <div class="card"><div class="n">{{ number_format($report['rows']) }}</div><div class="l">Rows read</div></div>
                <div class="card"><div class="n">{{ number_format($report['created']) }}</div><div class="l">New records</div></div>
                <div class="card"><div class="n">{{ number_format($report['updated']) }}</div><div class="l">Updated</div></div>
                <div class="card"><div class="n">{{ number_format($report['unchanged']) }}</div><div class="l">Already up to date</div></div>
                <div class="card"><div class="n">{{ number_format($report['skipped']) }}</div><div class="l">Skipped (errors)</div></div>
                <div class="card"><div class="n">{{ number_format($report['reference_created']) }}</div><div class="l">New schools / departments / programmes</div></div>
            </div>

            @if ($report['errors'])
                <h2>Skipped rows</h2>
                <table>
                    <thead><tr><th style="width:80px">Row</th><th>Problem</th></tr></thead>
                    <tbody>
                    @foreach ($report['errors'] as $item)
                        <tr><td>{{ $item['row'] }}</td><td>{{ $item['message'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
                @if ($report['skipped'] > count($report['errors']))
                    <p class="muted small">Showing the first {{ count($report['errors']) }} of {{ number_format($report['skipped']) }}.</p>
                @endif
                <p class="muted small">Fix these rows in the spreadsheet and import again; rows already imported will show as “already up to date”.</p>
            @endif

            @if ($report['warnings'])
                <h2 style="margin-top:18px">Warnings <span class="muted small">(imported, but worth a look)</span></h2>
                <table>
                    <thead><tr><th style="width:80px">Row</th><th>Note</th></tr></thead>
                    <tbody>
                    @foreach ($report['warnings'] as $item)
                        <tr><td>{{ $item['row'] }}</td><td>{{ $item['message'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            @if ($report['dry_run'] && ($report['created'] + $report['updated']) > 0)
                <div class="actions">
                    <button type="button" wire:click="commit" wire:loading.attr="disabled" wire:target="commit"
                            wire:confirm="Save {{ $report['created'] }} new and {{ $report['updated'] }} updated records?">
                        3. Import {{ number_format($report['created'] + $report['updated']) }} records
                    </button>
                    <span class="muted small" wire:loading wire:target="commit">Importing…</span>
                </div>
            @endif
        </div>
    @endif
</div>

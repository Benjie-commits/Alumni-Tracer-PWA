<div>
    <div class="head">
        <div>
            <h1>Alumni directory</h1>
            <span class="muted">{{ number_format($profiles->total()) }} {{ Str::plural('record', $profiles->total()) }} match your filters.</span>
        </div>
        <a class="btn secondary" href="{{ $exportUrl }}">Export CSV</a>
    </div>

    <div class="panel">
        <div class="filters">
            <label>Search
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $showContact ? 'Name, student no., email, phone' : 'Name or student no.' }}" style="width:260px">
            </label>
            <label>School
                <select wire:model.live="schoolId">
                    <option value="">All schools</option>
                    @foreach ($schools as $school)
                        <option value="{{ $school->id }}">{{ $school->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Department
                <select wire:model.live="departmentId" @disabled(! $schoolId)>
                    <option value="">{{ $schoolId ? 'All departments' : 'Choose a school first' }}</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Programme
                <select wire:model.live="programmeId">
                    <option value="">All programmes</option>
                    @foreach ($programmes as $programme)
                        <option value="{{ $programme->id }}">{{ $programme->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Graduated
                <select wire:model.live="year">
                    <option value="">Any year</option>
                    @foreach ($years as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </label>
            <label>Record status
                <select wire:model.live="status">
                    <option value="">Any</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label style="flex-direction:row;align-items:center;gap:6px">
                <input type="checkbox" wire:model.live="taOnly" style="min-width:auto"> Teaching-assistant candidates only
            </label>
            <button type="button" class="secondary" wire:click="clearFilters">Clear</button>
        </div>

        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Student no.</th>
                <th>Programme</th>
                <th>Year</th>
                <th>Status</th>
                <th>Doing now</th>
                @if ($showContact)<th>Contact</th>@endif
            </tr>
            </thead>
            <tbody>
            @forelse ($profiles as $profile)
                <tr wire:key="p{{ $profile->id }}">
                    <td>
                        <a href="{{ route('admin.alumni.show', $profile) }}">{{ $profile->last_name }}, {{ $profile->first_name }}</a>
                        @if ($profile->ta_flagged_at)<span class="pill" title="Strong graduate who said they are available as a teaching assistant">TA</span>@endif
                    </td>
                    <td>{{ $profile->student_number ?? '—' }}</td>
                    <td>
                        {{ $profile->programme?->name ?? '—' }}
                        @if ($profile->programme)
                            <div class="muted small">{{ $profile->programme->department?->school?->name }}</div>
                        @endif
                    </td>
                    <td>{{ $profile->graduation_year ?? '—' }}</td>
                    <td><span class="pill {{ $profile->verification_status->value }}">{{ $profile->verification_status->label() }}</span></td>
                    <td>{{ $profile->employment_status?->label() ?? '—' }}</td>
                    @if ($showContact)
                        <td class="small">{{ $profile->email }}<br>{{ $profile->phone }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $showContact ? 7 : 6 }}" class="empty">No alumni match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>

        {{ $profiles->links() }}
    </div>
</div>

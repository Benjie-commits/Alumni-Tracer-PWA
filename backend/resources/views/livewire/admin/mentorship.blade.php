<div>
    <div class="head">
        <div>
            <h1>Mentorship</h1>
            <span class="muted">Current students matched with alumni who offered to mentor (alumni opt in from their profile in the app).
                Students do not have accounts: record them here with their agreement.</span>
        </div>
    </div>

    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    <form class="panel" wire:submit="match">
        <h2>Match a student with a mentor</h2>
        @if ($mentors->isEmpty())
            <p class="muted">No alumni are available as mentors yet. Verified alumni can offer to mentor from their profile in the alumni app.</p>
        @endif
        <div class="grid3">
            <label class="field"><span>Student</span>
                <select wire:model="matchStudentId">
                    <option value="">Choose a student without a mentor…</option>
                    @foreach ($unmatched as $s)<option value="{{ $s->id }}">{{ $s->mentor_requested_at ? '★ ' : '' }}{{ $s->full_name }} ({{ $s->programme?->name ?? 'no programme' }}{{ $s->year_of_study ? ', year '.$s->year_of_study : '' }})</option>@endforeach
                </select>
                @error('matchStudentId')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Mentor</span>
                <select wire:model="matchMentorId">
                    <option value="">Choose a mentor…</option>
                    @foreach ($mentors as $m)
                        <option value="{{ $m->id }}">{{ $m->full_name }} · {{ $m->programme?->name ?? '—' }}, {{ $m->graduation_year ?? '—' }}{{ $m->mentor_topics ? ' · '.$m->mentor_topics : '' }} ({{ $m->active_mentees }} current)</option>
                    @endforeach
                </select>
                @error('matchMentorId')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Focus (optional)</span><input type="text" wire:model="focus" placeholder="e.g. careers in agribusiness">@error('focus')<span class="error">{{ $message }}</span>@enderror</label>
        </div>
        <div class="actions"><button type="submit">Make the match</button></div>
    </form>

    <div class="panel">
        <h2>Current mentorships</h2>
        <table>
            <thead><tr><th>Student</th><th>Mentor</th><th>Focus</th><th>Since</th><th></th></tr></thead>
            <tbody>
            @forelse ($active as $m)
                <tr wire:key="m{{ $m->id }}">
                    <td>{{ $m->student->full_name }}<br><span class="muted small">{{ $m->student->programme?->name }}</span></td>
                    <td><a href="{{ route('admin.alumni.show', $m->mentor) }}">{{ $m->mentor->full_name }}</a><br><span class="muted small">{{ $m->mentor->programme?->name }}, {{ $m->mentor->graduation_year }}</span></td>
                    <td class="small">{{ $m->focus ?? '—' }}</td>
                    <td class="small">{{ $m->started_at->format('j M Y') }}</td>
                    <td><button type="button" class="secondary" wire:click="endMatch({{ $m->id }})" wire:confirm="End this mentorship?">End</button></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No current mentorships.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if ($ended->isNotEmpty())
            <p class="muted small" style="margin-top:12px">Recently ended:
                @foreach ($ended as $m){{ $m->student->full_name }} with {{ $m->mentor->full_name }} ({{ $m->ended_at->format('j M Y') }})@if (! $loop->last); @endif @endforeach
            </p>
        @endif
    </div>

    <form class="panel" wire:submit="saveStudent">
        <h2>{{ $editingStudentId ? 'Edit student' : 'Add a current student' }}</h2>
        <div class="grid3">
            <label class="field"><span>Student number</span><input type="text" inputmode="numeric" wire:model="studentNumber" placeholder="2401600227">@error('studentNumber')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>First name</span><input type="text" wire:model="firstName">@error('firstName')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Surname</span><input type="text" wire:model="lastName">@error('lastName')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Programme</span>
                <select wire:model="programmeId">
                    <option value="">—</option>
                    @foreach ($programmes as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                </select>
                @error('programmeId')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Year of study</span><input type="number" min="1" max="7" wire:model="yearOfStudy">@error('yearOfStudy')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Email (optional)</span><input type="email" wire:model="email">@error('email')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Phone (optional)</span><input type="text" wire:model="phone">@error('phone')<span class="error">{{ $message }}</span>@enderror</label>
        </div>
        <div class="actions">
            <label><input type="checkbox" wire:model="contactShared"> The student agreed that their mentor may see their email and phone</label>
            <button type="submit">{{ $editingStudentId ? 'Save student' : 'Add student' }}</button>
            @if ($editingStudentId)<button type="button" class="secondary" wire:click="resetStudentForm">Cancel</button>@endif
        </div>
    </form>

    <div class="panel">
        <h2>Students ({{ $students->count() }})</h2>
        <table>
            <thead><tr><th>Student</th><th>Number</th><th>Programme</th><th>Year</th><th>Contact shared with mentor</th><th>Mentor</th><th></th></tr></thead>
            <tbody>
            @forelse ($students as $s)
                <tr wire:key="s{{ $s->id }}">
                    <td>
                        {{ $s->full_name }}
                        @if ($s->user_id)<span class="pill ok" title="Has their own app account">app</span>@endif
                        @if ($s->mentor_requested_at && ! $s->mentorships->first())
                            <br><span class="pill pending">Wants a mentor</span>
                            @if ($s->mentor_request_note)<span class="muted small">“{{ $s->mentor_request_note }}”</span>@endif
                        @endif
                    </td>
                    <td class="small">{{ $s->student_number }}</td>
                    <td class="small">{{ $s->programme?->name ?? '—' }}</td>
                    <td class="small">{{ $s->year_of_study ?? '—' }}</td>
                    <td class="small">{{ $s->contact_shared_at ? 'Yes' : 'No' }}</td>
                    <td class="small">{{ $s->mentorships->first()?->mentor?->full_name ?? '—' }}</td>
                    <td><button type="button" class="secondary" wire:click="editStudent({{ $s->id }})">Edit</button></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No students recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

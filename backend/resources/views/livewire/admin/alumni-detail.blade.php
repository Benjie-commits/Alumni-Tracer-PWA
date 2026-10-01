@php($ro = ! $this->canEdit)
<div>
    <div class="head">
        <div>
            <a href="{{ route('admin.alumni.index') }}" class="small">← Alumni directory</a>
            <h1>{{ $profile->full_name }}</h1>
            <span class="pill {{ $profile->verification_status->value }}">{{ $profile->verification_status->label() }}</span>
            @if ($profile->ta_flagged_at)
                <span class="pill" title="Strong graduate who said they are available as a teaching assistant">Teaching-assistant candidate</span>
            @endif
            <span class="muted small">
                · {{ $profile->record_source->label() }}
                @if ($profile->user) · account {{ $ro ? 'registered' : $profile->user->email }}, last sign-in {{ $profile->user->last_login_at?->diffForHumans() ?? 'never' }} @else · no account yet @endif
            </span>
        </div>
    </div>

    @if ($saved)
        <div class="alert ok">Saved.</div>
    @endif
    @if ($ro)
        <div class="alert warn">You have read-only access. Contact details and editing are limited to Registrar and ICT staff.</div>
    @endif
    @if ($profile->declared_student_number)
        <div class="alert warn">Self-declared student number: <strong>{{ $profile->declared_student_number }}</strong> (not matched to a Registrar record).</div>
    @endif

    <form wire:submit="save">
        <div class="panel">
            <h2>Academic record <span class="muted small">(Registrar-owned)</span></h2>
            <div class="grid3">
                <label class="field"><span>Student number</span>
                    <input type="text" wire:model="form.student_number" @disabled($ro)>
                    @error('form.student_number')<span class="error">{{ $message }}</span>@enderror</label>
                <label class="field"><span>First name</span>
                    <input type="text" wire:model="form.first_name" @disabled($ro)>
                    @error('form.first_name')<span class="error">{{ $message }}</span>@enderror</label>
                <label class="field"><span>Other names</span>
                    <input type="text" wire:model="form.other_names" @disabled($ro)></label>
                <label class="field"><span>Last name</span>
                    <input type="text" wire:model="form.last_name" @disabled($ro)>
                    @error('form.last_name')<span class="error">{{ $message }}</span>@enderror</label>
                <label class="field"><span>Gender</span>
                    <select wire:model="form.gender" @disabled($ro)>
                        <option value="">—</option>
                        <option value="female">Female</option>
                        <option value="male">Male</option>
                        <option value="other">Other</option>
                    </select></label>
                @unless ($ro)
                    <label class="field"><span>Date of birth</span>
                        <input type="date" wire:model="form.date_of_birth">
                        @error('form.date_of_birth')<span class="error">{{ $message }}</span>@enderror</label>
                @endunless
                <label class="field" style="grid-column: span 2"><span>Programme</span>
                    <select wire:model="form.programme_id" @disabled($ro)>
                        <option value="">—</option>
                        @foreach ($programmes->groupBy(fn ($p) => $p->department->school->name.' › '.$p->department->name) as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach ($items as $programme)
                                    <option value="{{ $programme->id }}">{{ $programme->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('form.programme_id')<span class="error">{{ $message }}</span>@enderror</label>
                <label class="field"><span>Graduation year</span>
                    <input type="number" wire:model="form.graduation_year" @disabled($ro)>
                    @error('form.graduation_year')<span class="error">{{ $message }}</span>@enderror</label>
                <label class="field"><span>Graduation date</span>
                    <input type="date" wire:model="form.graduation_date" @disabled($ro)></label>
                <label class="field"><span>Class of award</span>
                    <input type="text" wire:model="form.class_of_award" @disabled($ro)></label>
            </div>
        </div>

        <div class="panel">
            <h2>Alumnus-maintained details</h2>
            <div class="grid3">
                @unless ($ro)
                    <label class="field"><span>Email</span>
                        <input type="email" wire:model="form.email">
                        @error('form.email')<span class="error">{{ $message }}</span>@enderror</label>
                    <label class="field"><span>Phone</span>
                        <input type="text" wire:model="form.phone">
                        @error('form.phone')<span class="error">{{ $message }}</span>@enderror</label>
                    <label class="field"><span>WhatsApp</span>
                        <input type="text" wire:model="form.whatsapp_number">
                        @error('form.whatsapp_number')<span class="error">{{ $message }}</span>@enderror</label>
                @endunless
                <label class="field"><span>City</span>
                    <input type="text" wire:model="form.city" @disabled($ro)></label>
                <label class="field"><span>Country</span>
                    <input type="text" wire:model="form.country" @disabled($ro)></label>
                <label class="field"><span>Doing now</span>
                    <select wire:model="form.employment_status" @disabled($ro)>
                        <option value="">—</option>
                        @foreach ($employmentStatuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select></label>
                <label class="field"><span>Further study</span>
                    <select wire:model="form.further_study_status" @disabled($ro)>
                        <option value="">—</option>
                        @foreach ($furtherStudyStatuses as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select></label>
                <label class="field"><span>Institution</span>
                    <input type="text" wire:model="form.further_study_institution" @disabled($ro)></label>
                <label class="field"><span>Course</span>
                    <input type="text" wire:model="form.further_study_programme" @disabled($ro)></label>
            </div>
            <p class="muted small" style="margin-bottom:0">
                Last confirmed by the alumnus:
                {{ $profile->profile_updated_at ? $profile->profile_updated_at->format('j M Y').' ('.$profile->profile_updated_at->diffForHumans().')' : 'never' }}.
                Staff edits do not change this date.
            </p>

            @unless ($ro)
                <div class="actions">
                    <button type="submit" wire:loading.attr="disabled">Save changes</button>
                    <button type="button" class="secondary" wire:click="resetForm" wire:confirm="Discard unsaved changes?">Reset</button>
                </div>
            @endunless
        </div>
    </form>

    <div class="panel">
        <h2>Employment history <span class="muted small">(entered by the alumnus)</span></h2>
        <table>
            <thead><tr><th>Employer</th><th>Role</th><th>Type</th><th>From</th><th>To</th></tr></thead>
            <tbody>
            @forelse ($profile->employmentRecords->sortByDesc('start_date') as $job)
                <tr>
                    <td>{{ $job->employer }}</td>
                    <td>{{ $job->job_title ?? '—' }}</td>
                    <td>{{ $job->employment_type->label() }}</td>
                    <td>{{ $job->start_date?->format('M Y') ?? '—' }}</td>
                    <td>{{ $job->is_current ? 'Present' : ($job->end_date?->format('M Y') ?? '—') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nothing recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h2>Tracer surveys</h2>
        <table>
            <thead><tr><th>Survey</th><th>Due</th><th>Status</th><th>Reminders</th></tr></thead>
            <tbody>
            @forelse ($invitations as $invitation)
                <tr wire:key="inv{{ $invitation->id }}">
                    <td>{{ $invitation->cycle->title }}</td>
                    <td>{{ $invitation->due_at->setTimezone(config('sunates.timezone'))->format('j M Y') }}</td>
                    <td>
                        <span class="pill {{ $invitation->status === \App\Enums\SurveyInvitationStatus::Completed ? 'verified' : ($invitation->status === \App\Enums\SurveyInvitationStatus::Expired ? 'rejected' : '') }}">{{ $invitation->status->label() }}</span>
                        @if ($invitation->completed_at)<span class="muted small">{{ $invitation->completed_at->format('j M Y') }}</span>@endif
                    </td>
                    <td>{{ $invitation->reminders_sent }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Not invited to any survey yet. Surveys are sent 6 months, 1 year and 3 years after graduation.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @unless ($ro)
        <div class="panel">
            <h2>Messages</h2>
            <p style="margin-top:0">
                SMS and WhatsApp:
                @if ($messagingStopped)
                    <span class="pill rejected">Stopped by the alumnus</span>
                @else
                    <span class="pill verified">Allowed</span>
                    @if ($profile->hasOptedOut(\App\Enums\NotificationChannel::Sms) || $profile->hasOptedOut(\App\Enums\NotificationChannel::Whatsapp))
                        <span class="muted small">(one channel has been switched off by the alumnus)</span>
                    @endif
                @endif
            </p>
            <button type="button" class="secondary" wire:click="toggleMessaging"
                    wire:confirm="{{ $messagingStopped ? 'Turn SMS and WhatsApp messages back on for this person, at their request?' : 'Record that this person asked to stop SMS and WhatsApp messages?' }}">
                {{ $messagingStopped ? 'Turn messages back on' : 'Stop messages (at their request)' }}
            </button>
            <p class="muted small" style="margin-bottom:0">Use this only when the alumnus has asked you. They can also stop messages themselves from any SMS, by replying STOP on WhatsApp, or in the app.</p>
        </div>
    @endunless
</div>

<?php

namespace App\Livewire\Admin;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\AlumniProfile;
use App\Models\Programme;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Alumnus record')]
class AlumniDetail extends Component
{
    use AuthorizesStaff;

    private const FIELDS = [
        'student_number', 'first_name', 'other_names', 'last_name', 'gender', 'date_of_birth',
        'programme_id', 'graduation_year', 'graduation_date', 'class_of_award',
        'email', 'phone', 'whatsapp_number', 'country', 'city',
        'employment_status', 'further_study_status', 'further_study_institution', 'further_study_programme',
    ];

    /** Withheld from read-only staff. */
    private const PERSONAL_FIELDS = ['date_of_birth', 'email', 'phone', 'whatsapp_number'];

    public AlumniProfile $profile;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $saved = false;

    public function mount(AlumniProfile $profile): void
    {
        $this->profile = $profile;
        $this->fillForm();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()->canManageRecords();
    }

    public function save(): void
    {
        $this->authorizeManager();
        $this->saved = false;

        $this->form['student_number'] = strtoupper(trim((string) ($this->form['student_number'] ?? '')));

        $validated = $this->validate([
            'form.student_number' => ['nullable', 'string', 'max:64', Rule::unique('alumni_profiles', 'student_number')->ignore($this->profile->id)],
            'form.first_name' => ['required', 'string', 'max:255'],
            'form.other_names' => ['nullable', 'string', 'max:255'],
            'form.last_name' => ['required', 'string', 'max:255'],
            'form.gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'form.date_of_birth' => ['nullable', 'date', 'before:today'],
            'form.programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'form.graduation_year' => ['nullable', 'integer', 'between:1950,'.((int) date('Y') + 1)],
            'form.graduation_date' => ['nullable', 'date'],
            'form.class_of_award' => ['nullable', 'string', 'max:64'],
            'form.email' => ['nullable', 'email:rfc', 'max:255'],
            'form.phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,19}$/'],
            'form.whatsapp_number' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,19}$/'],
            'form.country' => ['nullable', 'string', 'max:100'],
            'form.city' => ['nullable', 'string', 'max:100'],
            'form.employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'form.further_study_status' => ['nullable', Rule::enum(FurtherStudyStatus::class)],
            'form.further_study_institution' => ['nullable', 'string', 'max:255'],
            'form.further_study_programme' => ['nullable', 'string', 'max:255'],
        ], [
            'form.phone.regex' => 'Enter a valid phone number, e.g. +256700000000.',
            'form.whatsapp_number.regex' => 'Enter a valid WhatsApp number, e.g. +256700000000.',
        ])['form'];

        // Empty inputs arrive as '' but the columns want NULL.
        $this->profile->fill(array_map(fn ($v) => $v === '' ? null : $v, $validated))->save();

        // Staff edits deliberately do not touch profile_updated_at: that timestamp means "the
        // alumnus themselves confirmed this", which is what the freshness nudges rely on.
        $this->fillForm();
        $this->saved = true;
    }

    public function resetForm(): void
    {
        $this->fillForm();
        $this->saved = false;
        $this->resetErrorBag();
    }

    private function fillForm(): void
    {
        $this->profile->refresh();

        // $form is sent to the browser with every response, so read-only viewers (QA/Deans) must
        // never receive personal contact details or dates of birth, even in fields the page hides.
        $fields = $this->canEdit
            ? self::FIELDS
            : array_values(array_diff(self::FIELDS, self::PERSONAL_FIELDS));

        $this->form = collect($fields)->mapWithKeys(function (string $field) {
            $value = $this->profile->{$field};

            return [$field => match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => $value ?? '',
            }];
        })->all();
    }

    public function render()
    {
        $this->profile->loadMissing('programme.department.school', 'user.role', 'employmentRecords', 'verifier');

        return view('livewire.admin.alumni-detail', [
            'programmes' => Programme::query()->with('department.school')->orderBy('name')->get(),
            'employmentStatuses' => EmploymentStatus::cases(),
            'furtherStudyStatuses' => FurtherStudyStatus::cases(),
        ]);
    }
}

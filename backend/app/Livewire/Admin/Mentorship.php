<?php

namespace App\Livewire\Admin;

use App\Enums\MentorshipStatus;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\AlumniProfile;
use App\Models\MentorshipMatch;
use App\Models\Programme;
use App\Models\Student;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * FR-5 mentorship: staff record the current students taking part and match each with an alumnus who has
 * offered to mentor. Students have no accounts (no student identity process has been decided), so every
 * match is made here by a member of staff. Registrar and ICT only: it is personal data about students.
 */
#[Layout('layouts::admin')]
#[Title('Mentorship')]
class Mentorship extends Component
{
    use AuthorizesStaff;

    // ---- the student form ----
    public ?int $editingStudentId = null;

    public string $studentNumber = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $programmeId = '';

    public string $yearOfStudy = '';

    public string $email = '';

    public string $phone = '';

    /** The student agreed that their mentor may see their email and phone. */
    public bool $contactShared = false;

    // ---- the match form ----
    public string $matchStudentId = '';

    public string $matchMentorId = '';

    public string $focus = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function saveStudent(): void
    {
        $this->authorizeManager();
        $this->notice = null;
        $this->studentNumber = \App\Support\StudentNumber::normalise($this->studentNumber);

        $this->validate([
            'studentNumber' => ['required', 'string', 'max:64', \App\Support\StudentNumber::rule(),Rule::unique('students', 'student_number')->ignore($this->editingStudentId)],
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'programmeId' => ['nullable', 'integer', 'exists:programmes,id'],
            'yearOfStudy' => ['nullable', 'integer', 'between:1,7'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,19}$/'],
        ], [
            'phone.regex' => 'Enter a valid phone number, e.g. +256700000000.',
        ]);

        $student = $this->editingStudentId ? Student::query()->findOrFail($this->editingStudentId) : new Student(['created_by' => auth()->id()]);

        $student->fill([
            'student_number' => $this->studentNumber,
            'first_name' => trim($this->firstName),
            'last_name' => trim($this->lastName),
            'programme_id' => $this->programmeId === '' ? null : (int) $this->programmeId,
            'year_of_study' => $this->yearOfStudy === '' ? null : (int) $this->yearOfStudy,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'contact_shared_at' => $this->contactShared ? ($student->contact_shared_at ?? now()) : null,
        ])->save();

        Audit::record($this->editingStudentId ? 'students.updated' : 'students.created', $student, $this->editingStudentId ? Audit::changesOf($student) : []);

        $this->notice = $this->editingStudentId ? 'Student updated.' : 'Student added. You can now match them with a mentor below.';
        $this->resetStudentForm();
    }

    public function editStudent(int $id): void
    {
        $this->authorizeManager();
        $student = Student::query()->findOrFail($id);

        $this->editingStudentId = $student->id;
        $this->studentNumber = $student->student_number;
        $this->firstName = $student->first_name;
        $this->lastName = $student->last_name;
        $this->programmeId = (string) ($student->programme_id ?? '');
        $this->yearOfStudy = (string) ($student->year_of_study ?? '');
        $this->email = (string) $student->email;
        $this->phone = (string) $student->phone;
        $this->contactShared = $student->contact_shared_at !== null;
        $this->resetErrorBag();
    }

    public function resetStudentForm(): void
    {
        $this->reset('editingStudentId', 'studentNumber', 'firstName', 'lastName', 'programmeId', 'yearOfStudy', 'email', 'phone', 'contactShared');
        $this->resetErrorBag();
    }

    public function match(): void
    {
        $this->authorizeManager();
        $this->notice = null;

        $this->validate([
            'matchStudentId' => ['required', 'integer', 'exists:students,id'],
            'matchMentorId' => ['required', 'integer'],
            'focus' => ['nullable', 'string', 'max:255'],
        ], [
            'matchStudentId.required' => 'Choose a student.',
            'matchMentorId.required' => 'Choose a mentor.',
        ]);

        DB::transaction(function () {
            $student = Student::query()->lockForUpdate()->findOrFail((int) $this->matchStudentId);

            // Only someone who offered to mentor and whose identity the Registrar has confirmed.
            $mentor = AlumniProfile::query()->availableMentors()->find((int) $this->matchMentorId);
            if ($mentor === null) {
                throw ValidationException::withMessages(['matchMentorId' => 'This alumnus is not available as a mentor (they have not offered, or are not verified).']);
            }

            if ($student->mentorships()->where('status', MentorshipStatus::Active)->exists()) {
                throw ValidationException::withMessages(['matchStudentId' => 'This student already has a mentor. End that mentorship first.']);
            }

            $match = MentorshipMatch::query()->create([
                'alumni_profile_id' => $mentor->id,
                'student_id' => $student->id,
                'status' => MentorshipStatus::Active,
                'focus' => $this->focus ?: null,
                'started_at' => now(),
                'created_by' => auth()->id(),
            ]);

            Audit::record('mentorship.created', $match);
        });

        $this->notice = 'Matched. The mentor sees the student in the alumni app; introduce them as agreed with the student.';
        $this->reset('matchStudentId', 'matchMentorId', 'focus');
    }

    public function endMatch(int $id): void
    {
        $this->authorizeManager();

        $match = MentorshipMatch::query()->where('status', MentorshipStatus::Active)->findOrFail($id);
        $match->update(['status' => MentorshipStatus::Ended, 'ended_at' => now(), 'ended_by' => auth()->id()]);
        Audit::record('mentorship.ended', $match);
    }

    public function render()
    {
        $this->authorizeManager();

        $students = Student::query()->with(['programme', 'mentorships' => fn ($q) => $q->where('status', MentorshipStatus::Active)->with('mentor')])
            ->orderBy('last_name')->orderBy('first_name')->get();

        return view('livewire.admin.mentorship', [
            'students' => $students,
            'unmatched' => $students->filter(fn (Student $s) => $s->mentorships->isEmpty()),
            'mentors' => AlumniProfile::query()->availableMentors()->with('programme')
                ->withCount(['mentorships as active_mentees' => fn ($q) => $q->where('status', MentorshipStatus::Active)])
                ->orderBy('last_name')->get(),
            'active' => MentorshipMatch::query()->where('status', MentorshipStatus::Active)->with(['mentor.programme', 'student.programme'])->latest('started_at')->get(),
            'ended' => MentorshipMatch::query()->where('status', MentorshipStatus::Ended)->with(['mentor', 'student'])->latest('ended_at')->limit(10)->get(),
            'programmes' => Programme::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}

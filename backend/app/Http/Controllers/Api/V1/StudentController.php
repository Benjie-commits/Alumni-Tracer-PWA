<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MentorshipStatus;
use App\Enums\RoleSlug;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use App\Support\StudentNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Continuing students: their own account for events, internships and asking for a mentor (FR-5).
 * If staff already recorded the student (mentorship page), the new account is attached to that record.
 */
class StudentController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string', 'max:64', StudentNumber::rule()],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'programme_id' => ['required', 'integer', Rule::exists('programmes', 'id')],
            'year_of_study' => ['required', 'integer', 'between:1,7'],
            'password' => ['required', 'string', Password::min(8)->max(128)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], [
            'email.unique' => 'An account with this email already exists. Try signing in instead.',
        ]);

        $number = StudentNumber::normalise($data['student_number']);
        $existing = Student::query()->where('student_number', $number)->first();

        if ($existing?->user_id) {
            throw ValidationException::withMessages(['student_number' => 'This student number already has an account. Sign in, or use "Forgot your password?".']);
        }

        // Staff may have recorded this student already. A student number alone is not proof of being them,
        // so the record is attached only when the email typed is the one staff hold for it.
        if ($existing !== null && ($existing->email === null || strcasecmp(trim($existing->email), trim($data['email'])) !== 0)) {
            throw ValidationException::withMessages(['student_number' => 'This student number is already on record. Register with the email the university holds for you, or ask the Registrar\'s office.']);
        }

        $user = DB::transaction(function () use ($data, $number, $existing) {
            $user = User::query()->create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role_id' => Role::idFor(RoleSlug::Student),
                'is_active' => true,
            ]);

            $fields = [
                'user_id' => $user->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'programme_id' => $data['programme_id'],
                'year_of_study' => $data['year_of_study'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? $existing?->phone,
            ];

            $existing ? $existing->update($fields) : Student::query()->create($fields + ['student_number' => $number]);

            return $user;
        });

        Audit::record('student.registered', $user);

        $device = $data['device_name'] ?? 'alumni-pwa';
        $user->load('role', 'student.programme');

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => new UserResource($user),
        ], 201);
    }

    /** The student's mentor request and, once staff have matched them, who their mentor is. */
    public function mentorship(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mentorshipState($this->student($request))]);
    }

    public function requestMentor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wants_mentor' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $student = $this->student($request);
        $student->update($data['wants_mentor']
            ? ['mentor_requested_at' => $student->mentor_requested_at ?? now(), 'mentor_request_note' => $data['note'] ?? null]
            : ['mentor_requested_at' => null, 'mentor_request_note' => null]);

        return response()->json(['data' => $this->mentorshipState($student->fresh())]);
    }

    private function mentorshipState(Student $student): array
    {
        $match = $student->mentorships()->where('status', MentorshipStatus::Active)->with('mentor.user')->latest('started_at')->first();
        $mentor = $match?->mentor;

        return [
            'requested' => $student->mentor_requested_at !== null,
            'requested_at' => $student->mentor_requested_at?->toIso8601String(),
            'note' => $student->mentor_request_note,
            'mentor' => $mentor ? [
                'name' => trim($mentor->first_name.' '.$mentor->last_name),
                'focus' => $match->focus,
                'email' => $mentor->user?->email,
                'since' => $match->started_at->toDateString(),
            ] : null,
        ];
    }

    private function student(Request $request): Student
    {
        return $request->user()->student()->firstOrFail();
    }
}

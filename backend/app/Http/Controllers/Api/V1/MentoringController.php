<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MentorshipStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\AlumniProfile;
use App\Models\MentorshipMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * FR-5 mentorship, the alumnus's side: offer (or stop offering) to mentor current students, and see the
 * students staff have matched with them. A student's email and phone are shown only if the student
 * agreed to share them; otherwise staff make the introduction.
 */
class MentoringController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->profile($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $data = $request->validate([
            'opted_in' => ['required', 'boolean'],
            'topics' => ['nullable', 'string', 'max:255'],
        ]);

        // Only graduates the Registrar's records confirm are put in touch with students.
        if ($data['opted_in'] && $profile->verification_status !== VerificationStatus::Verified) {
            throw ValidationException::withMessages(['opted_in' => 'Mentoring opens once the Registrar has verified your record.']);
        }

        $profile->forceFill([
            'mentor_opt_in_at' => $data['opted_in'] ? ($profile->mentor_opt_in_at ?? now()) : null,
            'mentor_topics' => $data['opted_in'] ? ($data['topics'] ?? null) : $profile->mentor_topics,
        ])->save();

        return response()->json(['data' => $this->present($profile)]);
    }

    private function profile(Request $request): AlumniProfile
    {
        return $request->user()->alumniProfile()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(AlumniProfile $profile): array
    {
        $mentees = $profile->mentorships()
            ->where('status', MentorshipStatus::Active)
            ->with('student.programme')
            ->latest('started_at')
            ->get();

        return [
            'opted_in' => $profile->mentor_opt_in_at !== null,
            'topics' => $profile->mentor_topics,
            'can_opt_in' => $profile->verification_status === VerificationStatus::Verified,
            'mentees' => $mentees->map(fn (MentorshipMatch $m) => [
                'id' => $m->id,
                'name' => $m->student->full_name,
                'programme' => $m->student->programme?->name,
                'year_of_study' => $m->student->year_of_study,
                'focus' => $m->focus,
                'since' => $m->started_at->toDateString(),
                // Contact details only with the student's agreement.
                'email' => $m->student->contact_shared_at ? $m->student->email : null,
                'phone' => $m->student->contact_shared_at ? $m->student->phone : null,
            ])->values(),
        ];
    }
}

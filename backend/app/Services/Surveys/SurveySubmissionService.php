<?php

namespace App\Services\Surveys;

use App\Enums\SurveyInvitationStatus;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an alumnus's answers to a survey (FR-3).
 *
 * Submissions are idempotent: the phone picks a submission id before sending, so if the connection
 * drops after the server saved the answers but before the phone heard back, the retry is recognised
 * and answered "already saved" instead of being stored twice or rejected.
 */
class SurveySubmissionService
{
    public function __construct(
        private readonly SurveyAnswers $answers,
        private readonly TeachingAssistantFlag $taFlag,
    ) {}

    /**
     * @param  array<string, mixed>  $rawAnswers
     * @param  int|null  $versionId  the questionnaire version the alumnus was shown, if they say
     *
     * @throws SurveyAlreadyCompleted this invitation was answered with a different submission
     * @throws SurveyClosed the window has closed
     * @throws ValidationException the answers do not fit the questionnaire
     */
    public function submit(SurveyInvitation $invitation, string $submissionId, array $rawAnswers, ?int $versionId = null): SubmissionResult
    {
        return DB::transaction(function () use ($invitation, $submissionId, $rawAnswers, $versionId) {
            // Lock so two phones (or a retry racing the original) cannot both record an answer.
            $invitation = SurveyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($existing = $invitation->response) {
                if ($existing->submission_id === $submissionId) {
                    return new SubmissionResult($existing, false);
                }

                throw new SurveyAlreadyCompleted;
            }

            if (! $invitation->isOpen()) {
                throw new SurveyClosed;
            }

            $version = $this->versionFor($invitation, $versionId);
            $answers = $this->answers->validate($version, $rawAnswers);
            $mapped = $this->answers->mapped($version->questions(), $answers);

            $response = SurveyResponse::query()->create([
                'survey_invitation_id' => $invitation->id,
                'tracer_survey_version_id' => $version->id,
                'alumni_profile_id' => $invitation->alumni_profile_id,
                'submission_id' => $submissionId,
                'answers' => $answers,
                'employment_status' => $mapped['employment_status'],
                'further_study_status' => $mapped['further_study_status'],
                'ta_interest' => $mapped['ta_interest'],
                'submitted_at' => now(),
            ]);

            $invitation->update(['status' => SurveyInvitationStatus::Completed, 'completed_at' => now()]);

            $this->updateProfile($invitation, $mapped);

            return new SubmissionResult($response, true);
        });
    }

    /**
     * Answers were given against the version the alumnus saw, provided it still belongs to this
     * survey; otherwise the current one.
     */
    private function versionFor(SurveyInvitation $invitation, ?int $versionId): TracerSurveyVersion
    {
        $cycle = $invitation->cycle;

        if ($versionId !== null) {
            $seen = $cycle->versions()->find($versionId);
            if ($seen) {
                return $seen;
            }
        }

        return $cycle->currentVersion ?? throw new SurveyClosed;
    }

    /**
     * What the alumnus just told us is also their current situation, so their profile follows. It counts
     * as confirmation too: a reply proves the phone number works and stops needless nudges.
     *
     * @param  array{employment_status: ?string, further_study_status: ?string, ta_interest: ?bool}  $mapped
     */
    private function updateProfile(SurveyInvitation $invitation, array $mapped): void
    {
        $profile = $invitation->profile()->lockForUpdate()->firstOrFail();

        $profile->fill(array_filter([
            'employment_status' => $mapped['employment_status'],
            'further_study_status' => $mapped['further_study_status'],
        ], fn ($value) => $value !== null));

        $profile->profile_updated_at = now();
        $profile->last_survey_completed_at = now();
        $this->taFlag->apply($profile, $mapped['ta_interest']);
        $profile->save();
    }
}

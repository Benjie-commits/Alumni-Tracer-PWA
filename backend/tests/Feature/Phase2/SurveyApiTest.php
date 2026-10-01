<?php

namespace Tests\Feature\Phase2;

use App\Enums\SurveyInvitationStatus;
use App\Models\AlumniProfile;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyCycle;
use App\Models\User;
use App\Services\Surveys\SurveyDefinitionSync;
use Illuminate\Support\Str;

class SurveyApiTest extends Phase2TestCase
{
    private function invitation(?AlumniProfile $profile = null, int $months = 6): SurveyInvitation
    {
        $profile ??= $this->graduate();
        $cycle = TracerSurveyCycle::where('milestone_months', $months)->firstOrFail();

        return SurveyInvitation::create([
            'tracer_survey_cycle_id' => $cycle->id,
            'alumni_profile_id' => $profile->id,
            'token' => Str::random(40),
            'status' => SurveyInvitationStatus::Sent,
            'due_at' => now()->subDay(),
            'expires_at' => now()->addDays(60),
            'sent_at' => now()->subDay(),
        ]);
    }

    /** @return array<string, mixed> a complete, valid set of answers to the 6-month survey */
    private function answers(array $overrides = []): array
    {
        return $overrides + [
            'current_activity' => 'employed',
            'employer_name' => 'Soroti Fruit Factory',
            'job_title' => 'Quality Officer',
            'work_related_to_studies' => 'closely',
            'time_to_first_job' => 'under_3_months',
            'further_study' => 'none',
            'started_business' => false,
            'ta_interest' => true,
        ];
    }

    private function submit(SurveyInvitation $invitation, array $answers, ?string $submissionId = null, array $extra = [])
    {
        return $this->postJson("/api/v1/surveys/{$invitation->token}/responses", [
            'submission_id' => $submissionId ?? (string) Str::uuid(),
            'answers' => $answers,
        ] + $extra);
    }

    // ---- reading the survey ------------------------------------------------------------

    public function test_the_link_shows_the_survey_and_almost_nothing_about_the_person(): void
    {
        $invitation = $this->invitation($this->graduate(['first_name' => 'Amina', 'last_name' => 'Okello', 'phone' => '0700111222']));

        $response = $this->getJson("/api/v1/surveys/{$invitation->token}")->assertOk();

        $data = $response->json('data');
        $this->assertSame('open', $data['status']);
        $this->assertSame('Amina', $data['first_name']);
        $this->assertSame('6-month graduate survey', $data['title']);
        $this->assertSame('6 months', $data['period']);
        $this->assertNotEmpty($data['questions']);
        $this->assertNotEmpty($data['intro']);

        $body = $response->getContent();
        foreach (['Okello', '0700111222', 'student_number', 'email', $invitation->token] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "the public survey must not reveal {$secret}");
        }
    }

    public function test_internal_column_mappings_are_not_sent_to_the_phone(): void
    {
        $invitation = $this->invitation();

        $questions = $this->getJson("/api/v1/surveys/{$invitation->token}")->json('data.questions');

        foreach ($questions as $question) {
            $this->assertArrayNotHasKey('maps_to', $question);
        }
        $this->assertSame('current_activity', $questions[0]['key']);
    }

    public function test_an_unknown_token_is_a_plain_not_found(): void
    {
        $this->getJson('/api/v1/surveys/'.Str::random(40))->assertNotFound();
        $this->postJson('/api/v1/surveys/'.Str::random(40).'/responses', ['submission_id' => (string) Str::uuid(), 'answers' => $this->answers()])->assertNotFound();
    }

    public function test_a_completed_survey_says_so_without_showing_questions(): void
    {
        $invitation = $this->invitation();
        $this->submit($invitation, $this->answers())->assertCreated();

        $data = $this->getJson("/api/v1/surveys/{$invitation->token}")->assertOk()->json('data');

        $this->assertSame('completed', $data['status']);
        $this->assertArrayNotHasKey('questions', $data);
    }

    public function test_an_expired_survey_says_so(): void
    {
        $invitation = $this->invitation();
        $invitation->update(['expires_at' => now()->subMinute()]);

        $data = $this->getJson("/api/v1/surveys/{$invitation->token}")->assertOk()->json('data');

        $this->assertSame('expired', $data['status']);
        $this->assertArrayNotHasKey('questions', $data);
    }

    // ---- submitting --------------------------------------------------------------------

    public function test_a_response_is_recorded_against_the_version_shown(): void
    {
        $invitation = $this->invitation();

        $this->submit($invitation, $this->answers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.already_saved', false);

        $response = SurveyResponse::sole();
        $this->assertSame($invitation->id, $response->survey_invitation_id);
        $this->assertSame($invitation->alumni_profile_id, $response->alumni_profile_id);
        $this->assertSame($invitation->cycle->current_version_id, $response->tracer_survey_version_id);
        $this->assertSame('Soroti Fruit Factory', $response->answers['employer_name']);
        $this->assertSame('employed', $response->employment_status);
        $this->assertSame('none', $response->further_study_status);
        $this->assertTrue($response->ta_interest);

        $invitation->refresh();
        $this->assertSame(SurveyInvitationStatus::Completed, $invitation->status);
        $this->assertNotNull($invitation->completed_at);
    }

    public function test_answering_updates_the_alumnus_profile_and_counts_as_a_confirmation(): void
    {
        $profile = $this->graduate(['employment_status' => null, 'profile_updated_at' => null]);
        $invitation = $this->invitation($profile);

        $this->submit($invitation, $this->answers(['current_activity' => 'self_employed', 'employer_name' => 'My shop', 'further_study' => 'planned']))
            ->assertCreated();

        $profile->refresh();
        $this->assertSame('self_employed', $profile->employment_status->value);
        $this->assertSame('planned', $profile->further_study_status->value);
        $this->assertNotNull($profile->profile_updated_at, 'a survey answer counts as the alumnus confirming their record');
        $this->assertNotNull($profile->last_survey_completed_at);
    }

    public function test_a_survey_answered_by_someone_who_never_registered_still_works(): void
    {
        $profile = $this->graduate(['user_id' => null]);

        $this->submit($this->invitation($profile), $this->answers())->assertCreated();

        $this->assertSame('employed', $profile->fresh()->employment_status->value);
    }

    public function test_a_retry_with_the_same_submission_id_is_recognised_not_duplicated(): void
    {
        $invitation = $this->invitation();
        $submissionId = (string) Str::uuid();

        $this->submit($invitation, $this->answers(), $submissionId)->assertCreated();
        $this->submit($invitation, $this->answers(), $submissionId)
            ->assertOk()
            ->assertJsonPath('data.already_saved', true);

        $this->assertSame(1, SurveyResponse::count());
    }

    public function test_a_retry_still_succeeds_after_the_window_has_closed(): void
    {
        // The phone answered in time, lost signal before hearing back, and retried the next day.
        $invitation = $this->invitation();
        $submissionId = (string) Str::uuid();
        $this->submit($invitation, $this->answers(), $submissionId)->assertCreated();

        $invitation->update(['expires_at' => now()->subDay()]);

        $this->submit($invitation, $this->answers(), $submissionId)->assertOk()->assertJsonPath('data.already_saved', true);
    }

    public function test_a_second_different_submission_is_refused(): void
    {
        $invitation = $this->invitation();
        $this->submit($invitation, $this->answers())->assertCreated();

        $this->submit($invitation, $this->answers(['employer_name' => 'Someone else']))->assertStatus(409);

        $this->assertSame('Soroti Fruit Factory', SurveyResponse::sole()->answers['employer_name']);
    }

    public function test_an_expired_survey_cannot_be_answered(): void
    {
        $invitation = $this->invitation();
        $invitation->update(['expires_at' => now()->subMinute()]);

        $this->submit($invitation, $this->answers())->assertStatus(410);

        $this->assertSame(0, SurveyResponse::count());
    }

    public function test_invalid_answers_are_rejected_field_by_field_and_nothing_is_saved(): void
    {
        $invitation = $this->invitation();

        $response = $this->submit($invitation, $this->answers(['employer_name' => '', 'work_related_to_studies' => 'nonsense']))
            ->assertUnprocessable();

        $response->assertJsonValidationErrors(['answers.employer_name', 'answers.work_related_to_studies']);
        $this->assertSame(0, SurveyResponse::count());
        $this->assertTrue($invitation->fresh()->isOpen(), 'the alumnus can fix it and try again');
    }

    public function test_the_envelope_is_validated(): void
    {
        $invitation = $this->invitation();

        $this->postJson("/api/v1/surveys/{$invitation->token}/responses", ['answers' => $this->answers()])
            ->assertUnprocessable()->assertJsonValidationErrors('submission_id');

        $this->postJson("/api/v1/surveys/{$invitation->token}/responses", ['submission_id' => 'not-a-uuid', 'answers' => $this->answers()])
            ->assertUnprocessable()->assertJsonValidationErrors('submission_id');

        $this->postJson("/api/v1/surveys/{$invitation->token}/responses", ['submission_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('answers');
    }

    public function test_answers_to_questions_that_do_not_apply_are_not_stored(): void
    {
        $invitation = $this->invitation();

        $this->submit($invitation, $this->answers(['current_activity' => 'unemployed', 'employer_name' => 'Left over', 'job_search_challenges' => ['no_vacancies']]))
            ->assertCreated();

        $answers = SurveyResponse::sole()->answers;
        $this->assertArrayNotHasKey('employer_name', $answers);
        $this->assertSame(['no_vacancies'], $answers['job_search_challenges']);
    }

    // ---- versions ----------------------------------------------------------------------

    public function test_answers_are_kept_against_the_version_the_alumnus_was_shown_even_if_it_changed(): void
    {
        $invitation = $this->invitation();
        $shown = $this->getJson("/api/v1/surveys/{$invitation->token}")->json('data.version');

        // The questionnaire is revised while the alumnus is still filling it in.
        $milestones = config('tracer_surveys.milestones');
        $milestones[6]['definition']['intro'] = 'Revised introduction.';
        app(SurveyDefinitionSync::class)->sync($milestones);
        $current = TracerSurveyCycle::where('milestone_months', 6)->first()->current_version_id;
        $this->assertNotSame($shown, $current, 'precondition: a new version now exists');

        $this->submit($invitation, $this->answers(), null, ['version' => $shown])->assertCreated();

        $this->assertSame($shown, SurveyResponse::sole()->tracer_survey_version_id);
    }

    public function test_an_unknown_version_falls_back_to_the_current_one(): void
    {
        $invitation = $this->invitation();

        $this->submit($invitation, $this->answers(), null, ['version' => 999999])->assertCreated();

        $this->assertSame($invitation->cycle->current_version_id, SurveyResponse::sole()->tracer_survey_version_id);
    }

    public function test_a_version_belonging_to_another_survey_is_not_accepted(): void
    {
        $invitation = $this->invitation();
        $otherSurveyVersion = TracerSurveyCycle::where('milestone_months', 36)->first()->current_version_id;

        $this->submit($invitation, $this->answers(), null, ['version' => $otherSurveyVersion])->assertCreated();

        $this->assertSame($invitation->cycle->current_version_id, SurveyResponse::sole()->tracer_survey_version_id);
    }

    // ---- teaching-assistant flag -------------------------------------------------------

    public function test_a_strong_available_graduate_is_flagged_for_teaching_assistant_consideration(): void
    {
        $profile = $this->graduate(['class_of_award' => 'First Class']);

        $this->submit($this->invitation($profile), $this->answers(['ta_interest' => true]))->assertCreated();

        $this->assertNotNull($profile->fresh()->ta_flagged_at);
        $this->assertSame(1, AlumniProfile::taCandidates()->count());
    }

    public function test_the_class_of_award_match_ignores_case_and_spacing(): void
    {
        $profile = $this->graduate(['class_of_award' => '  SECOND CLASS UPPER ']);

        $this->submit($this->invitation($profile), $this->answers())->assertCreated();

        $this->assertNotNull($profile->fresh()->ta_flagged_at);
    }

    public function test_an_available_graduate_who_is_not_in_an_eligible_class_is_not_flagged(): void
    {
        $profile = $this->graduate(['class_of_award' => 'Second Class Lower']);

        $this->submit($this->invitation($profile), $this->answers(['ta_interest' => true]))->assertCreated();

        $this->assertNull($profile->fresh()->ta_flagged_at);
    }

    public function test_a_strong_graduate_who_is_not_available_is_not_flagged(): void
    {
        $profile = $this->graduate(['class_of_award' => 'First Class']);

        $this->submit($this->invitation($profile), $this->answers(['ta_interest' => false]))->assertCreated();

        $this->assertNull($profile->fresh()->ta_flagged_at);
    }

    public function test_leaving_the_question_blank_keeps_an_existing_flag(): void
    {
        $profile = $this->graduate(['class_of_award' => 'First Class', 'ta_flagged_at' => now()->subMonth()]);
        $answers = $this->answers();
        unset($answers['ta_interest']);

        $this->submit($this->invitation($profile), $answers)->assertCreated();

        $this->assertNotNull($profile->fresh()->ta_flagged_at);
    }

    public function test_changing_your_mind_at_a_later_survey_clears_the_flag(): void
    {
        $profile = $this->graduate(['class_of_award' => 'First Class', 'ta_flagged_at' => now()->subYear()]);

        $this->submit($this->invitation($profile), $this->answers(['ta_interest' => false]))->assertCreated();

        $this->assertNull($profile->fresh()->ta_flagged_at);
    }

    // ---- abuse -------------------------------------------------------------------------

    public function test_guessing_survey_links_is_rate_limited(): void
    {
        foreach (range(1, 30) as $_) {
            $this->getJson('/api/v1/surveys/'.Str::random(40))->assertNotFound();
        }

        $this->getJson('/api/v1/surveys/'.Str::random(40))->assertTooManyRequests();
    }

    // ---- in the app --------------------------------------------------------------------

    public function test_a_signed_in_alumnus_sees_only_their_own_open_surveys(): void
    {
        $user = User::factory()->alumnus()->create();
        $mine = $this->graduate(['user_id' => $user->id]);
        $mineOpen = $this->invitation($mine, 6);
        $mineDone = $this->invitation($mine, 12);
        $mineDone->update(['status' => SurveyInvitationStatus::Completed]);
        $mineExpired = $this->invitation($mine, 36);
        $mineExpired->update(['expires_at' => now()->subDay()]);
        $theirs = $this->invitation($this->graduate(['first_name' => 'Peter']), 6);

        $tokens = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/surveys')->assertOk()->json('data.*.token');

        $this->assertSame([$mineOpen->token], $tokens);
        $this->assertNotContains($theirs->token, $tokens);
    }

    public function test_the_app_survey_list_needs_an_alumni_sign_in(): void
    {
        $this->getJson('/api/v1/me/surveys')->assertUnauthorized();
        $this->actingAs(User::factory()->registrar()->create(), 'sanctum')->getJson('/api/v1/me/surveys')->assertForbidden();
    }

    // ---- notification preferences ------------------------------------------------------

    public function test_an_alumnus_can_switch_sms_and_whatsapp_off_and_on_independently(): void
    {
        $user = User::factory()->alumnus()->create();
        $profile = $this->graduate(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/me/notification-preferences')->assertOk()->assertExactJson(['data' => ['sms' => true, 'whatsapp' => true]]);

        $this->putJson('/api/v1/me/notification-preferences', ['sms' => false])->assertOk()->assertExactJson(['data' => ['sms' => false, 'whatsapp' => true]]);
        $this->assertNotNull($profile->fresh()->sms_opt_out_at);
        $this->assertNull($profile->fresh()->whatsapp_opt_out_at);

        $this->putJson('/api/v1/me/notification-preferences', ['sms' => true, 'whatsapp' => false])->assertOk()->assertExactJson(['data' => ['sms' => true, 'whatsapp' => false]]);
        $this->assertNull($profile->fresh()->sms_opt_out_at);
    }

    public function test_choosing_not_to_be_messaged_is_not_a_record_confirmation(): void
    {
        $user = User::factory()->alumnus()->create();
        $profile = $this->graduate(['user_id' => $user->id, 'profile_updated_at' => null]);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/notification-preferences', ['whatsapp' => false])->assertOk();

        $this->assertNull($profile->fresh()->profile_updated_at);
    }

    public function test_preferences_are_validated(): void
    {
        $user = User::factory()->alumnus()->create();
        $this->graduate(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/notification-preferences', ['sms' => 'perhaps'])
            ->assertUnprocessable()->assertJsonValidationErrors('sms');
    }
}

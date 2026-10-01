<?php

namespace Tests\Unit;

use App\Models\TracerSurveyVersion;
use App\Services\Surveys\SurveyAnswers;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SurveyAnswersTest extends TestCase
{
    private SurveyAnswers $answers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->answers = new SurveyAnswers;
    }

    /** A small questionnaire exercising every question type and a conditional. */
    private function version(): TracerSurveyVersion
    {
        $status = ['employed' => 'Employed', 'unemployed' => 'Looking'];

        return new TracerSurveyVersion(['definition' => ['questions' => [
            ['key' => 'activity', 'type' => 'single_choice', 'label' => 'Activity', 'required' => true, 'maps_to' => 'employment_status',
                'options' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys($status), $status)],
            ['key' => 'employer', 'type' => 'text', 'label' => 'Employer', 'required' => true, 'show_if' => ['key' => 'activity', 'in' => ['employed']]],
            ['key' => 'hurdles', 'type' => 'multi_choice', 'label' => 'Hurdles', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
            ['key' => 'staff', 'type' => 'number', 'label' => 'Staff', 'min' => 0, 'max' => 1000],
            ['key' => 'rating', 'type' => 'scale', 'label' => 'Rating', 'min' => 1, 'max' => 5],
            ['key' => 'started', 'type' => 'yes_no', 'label' => 'Started', 'required' => true],
            ['key' => 'ta', 'type' => 'yes_no', 'label' => 'TA', 'maps_to' => 'ta_interest'],
            ['key' => 'notes', 'type' => 'long_text', 'label' => 'Notes'],
        ]]]);
    }

    /** @return array<string, mixed> */
    private function good(array $overrides = []): array
    {
        return $overrides + ['activity' => 'employed', 'employer' => 'Acme', 'started' => true];
    }

    private function errorsFor(array $raw): array
    {
        try {
            $this->answers->validate($this->version(), $raw);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    public function test_valid_answers_come_back_cleaned(): void
    {
        $clean = $this->answers->validate($this->version(), $this->good([
            'employer' => '  Acme Ltd  ', 'hurdles' => ['a', 'b', 'a'], 'staff' => '12', 'rating' => '4', 'started' => 'yes', 'ta' => 0, 'notes' => 'Great',
        ]));

        $this->assertSame([
            'activity' => 'employed', 'employer' => 'Acme Ltd', 'hurdles' => ['a', 'b'], 'staff' => 12,
            'rating' => 4, 'started' => true, 'ta' => false, 'notes' => 'Great',
        ], $clean);
    }

    public function test_required_questions_must_be_answered(): void
    {
        $errors = $this->errorsFor([]);

        $this->assertArrayHasKey('answers.activity', $errors);
        $this->assertArrayHasKey('answers.started', $errors);
        $this->assertArrayNotHasKey('answers.employer', $errors, 'a hidden question is never required');
    }

    public function test_a_conditional_question_becomes_required_when_it_applies(): void
    {
        $errors = $this->errorsFor(['activity' => 'employed', 'started' => false]);

        $this->assertSame(['answers.employer'], array_keys($errors));
        $this->assertSame('Please answer this question.', $errors['answers.employer'][0]);
    }

    public function test_answers_to_questions_that_do_not_apply_are_dropped_not_rejected(): void
    {
        $clean = $this->answers->validate($this->version(), ['activity' => 'unemployed', 'started' => false, 'employer' => 'Left over', 'ghost' => 'x']);

        $this->assertSame(['activity' => 'unemployed', 'started' => false], $clean);
    }

    public function test_an_invalid_answer_to_a_hidden_question_is_ignored(): void
    {
        // employer would be a text question but 42 is not text; it is hidden, so nobody minds.
        $clean = $this->answers->validate($this->version(), ['activity' => 'unemployed', 'started' => true, 'employer' => 42]);

        $this->assertArrayNotHasKey('employer', $clean);
    }

    public function test_each_type_rejects_bad_values(): void
    {
        $cases = [
            'activity' => 'astronaut',
            'hurdles' => ['a', 'zzz'],
            'staff' => 'lots',
            'rating' => 9,
            'started' => 'maybe',
        ];

        foreach ($cases as $key => $bad) {
            $errors = $this->errorsFor($this->good([$key => $bad]));
            $this->assertArrayHasKey("answers.{$key}", $errors, "{$key} should reject ".json_encode($bad));
        }
    }

    public function test_number_and_scale_bounds_are_enforced(): void
    {
        $this->assertArrayHasKey('answers.staff', $this->errorsFor($this->good(['staff' => -1])));
        $this->assertArrayHasKey('answers.staff', $this->errorsFor($this->good(['staff' => 1001])));
        $this->assertArrayHasKey('answers.rating', $this->errorsFor($this->good(['rating' => 0])));
        $this->assertArrayHasKey('answers.rating', $this->errorsFor($this->good(['rating' => 3.5])));
        $this->assertSame([], $this->errorsFor($this->good(['staff' => 0, 'rating' => 5])));
    }

    public function test_text_length_limits_apply(): void
    {
        $this->assertArrayHasKey('answers.employer', $this->errorsFor($this->good(['employer' => str_repeat('x', 256)])));
        $this->assertArrayHasKey('answers.notes', $this->errorsFor($this->good(['notes' => str_repeat('x', 2001)])));
        $this->assertSame([], $this->errorsFor($this->good(['notes' => str_repeat('x', 2000)])));
    }

    public function test_empty_optional_answers_are_simply_absent(): void
    {
        $clean = $this->answers->validate($this->version(), $this->good(['hurdles' => [], 'notes' => '', 'staff' => null]));

        $this->assertSame(['activity' => 'employed', 'employer' => 'Acme', 'started' => true], $clean);
    }

    public function test_mapped_answers_feed_the_dedicated_columns(): void
    {
        $version = $this->version();
        $clean = $this->answers->validate($version, $this->good(['ta' => true]));

        $this->assertSame(
            ['employment_status' => 'employed', 'further_study_status' => null, 'ta_interest' => true],
            $this->answers->mapped($version->questions(), $clean)
        );
    }

    public function test_the_shipped_questionnaires_accept_a_realistic_response(): void
    {
        foreach (config('tracer_surveys.milestones') as $months => $survey) {
            $version = new TracerSurveyVersion(['definition' => $survey['definition']]);

            $clean = $this->answers->validate($version, [
                'current_activity' => 'employed', 'employer_name' => 'Soroti Fruit Factory', 'job_title' => 'Officer',
                'work_related_to_studies' => 'closely', 'time_to_first_job' => 'under_3_months',
                'further_study' => 'none', 'started_business' => false,
                'programme_prepared_me' => 4, 'ta_interest' => true,
            ]);

            $this->assertSame('employed', $clean['current_activity'], "{$months}-month survey");
            $this->assertArrayNotHasKey('job_search_challenges', $clean, 'job-search hurdles are for people looking for work');
        }
    }
}

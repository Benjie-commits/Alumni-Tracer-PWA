<?php

namespace Tests\Unit;

use App\Services\Surveys\SurveyDefinition;
use InvalidArgumentException;
use Tests\TestCase;

class SurveyDefinitionTest extends TestCase
{
    /** @return array<string, mixed> */
    private function q(string $key, array $extra = []): array
    {
        return $extra + ['key' => $key, 'type' => 'text', 'label' => "Question {$key}"];
    }

    private function choice(string $key, array $extra = []): array
    {
        return $this->q($key, $extra + [
            'type' => 'single_choice',
            'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
        ]);
    }

    private function assertInvalid(array $questions, string $messageContains): void
    {
        try {
            SurveyDefinition::assertValid(['questions' => $questions]);
            $this->fail('Expected the definition to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($messageContains, $e->getMessage());
        }
    }

    public function test_the_shipped_questionnaires_are_all_valid(): void
    {
        foreach (config('tracer_surveys.milestones') as $months => $survey) {
            SurveyDefinition::assertValid($survey['definition']);
            $this->assertNotEmpty($survey['title'], "{$months}-month survey needs a title");
        }

        $this->assertSame([6, 12, 36], array_keys(config('tracer_surveys.milestones')));
    }

    public function test_a_valid_definition_passes(): void
    {
        SurveyDefinition::assertValid(['questions' => [$this->q('name'), $this->choice('pick')]]);
        $this->addToAssertionCount(1);
    }

    public function test_structural_mistakes_are_caught(): void
    {
        $this->assertInvalid([], 'at least one question');
        $this->assertInvalid([$this->q('Bad Key')], 'keys must be');
        $this->assertInvalid([$this->q('a'), $this->q('a')], 'duplicate key');
        $this->assertInvalid([$this->q('a', ['type' => 'essay'])], "unknown type 'essay'");
        $this->assertInvalid([$this->q('a', ['label' => ' '])], 'needs a label');
        $this->assertInvalid([$this->q('a', ['type' => 'single_choice', 'options' => [['value' => 'x', 'label' => 'X']]])], 'at least two options');
        $this->assertInvalid([$this->choice('a', ['options' => [['value' => 'x', 'label' => 'X'], ['value' => 'x', 'label' => 'Y']]])], "duplicate option value 'x'");
        $this->assertInvalid([$this->q('n', ['type' => 'number', 'min' => 5, 'max' => 1])], 'min is greater than max');
    }

    public function test_show_if_must_refer_to_an_earlier_question(): void
    {
        $this->assertInvalid([$this->q('a', ['show_if' => ['key' => 'b', 'in' => ['x']]]), $this->q('b')], 'comes earlier');
        $this->assertInvalid([$this->q('a', ['show_if' => ['key' => 'a', 'in' => ['x']]])], 'comes earlier');
        $this->assertInvalid([$this->choice('a'), $this->q('b', ['show_if' => ['key' => 'a', 'in' => []]])], "non-empty 'in'");
    }

    public function test_mapped_questions_must_be_the_right_shape(): void
    {
        $this->assertInvalid([$this->q('a', ['maps_to' => 'salary'])], "unknown maps_to 'salary'");
        $this->assertInvalid([$this->q('a', ['maps_to' => 'employment_status'])], 'must be single_choice');
        $this->assertInvalid([$this->choice('a', ['maps_to' => 'employment_status'])], 'not a valid employment_status value');
        $this->assertInvalid([$this->choice('a', ['type' => 'text', 'maps_to' => 'ta_interest'])], 'must be yes_no');

        $status = fn (string $key) => $this->q($key, [
            'type' => 'single_choice', 'maps_to' => 'employment_status',
            'options' => [['value' => 'employed', 'label' => 'Employed'], ['value' => 'other', 'label' => 'Other']],
        ]);
        $this->assertInvalid([$status('a'), $status('b')], 'another question already maps to employment_status');
    }

    public function test_visibility_follows_earlier_answers(): void
    {
        $questions = [
            $this->choice('activity'),
            $this->q('employer', ['show_if' => ['key' => 'activity', 'in' => ['a']]]),
            $this->q('detail', ['show_if' => ['key' => 'employer', 'in' => ['Acme']]]),
        ];

        $keys = fn (array $answers) => array_column(SurveyDefinition::visible($questions, $answers), 'key');

        $this->assertSame(['activity'], $keys([]));
        $this->assertSame(['activity'], $keys(['activity' => 'b']));
        $this->assertSame(['activity', 'employer'], $keys(['activity' => 'a']));
        $this->assertSame(['activity', 'employer', 'detail'], $keys(['activity' => 'a', 'employer' => 'Acme']));
    }

    public function test_an_answer_to_a_hidden_question_does_not_unlock_others(): void
    {
        $questions = [
            $this->choice('activity'),
            $this->q('employer', ['show_if' => ['key' => 'activity', 'in' => ['a']]]),
            $this->q('detail', ['show_if' => ['key' => 'employer', 'in' => ['Acme']]]),
        ];

        // 'employer' was answered "Acme" earlier, then the alumnus changed activity to b: 'detail' must stay hidden.
        $visible = SurveyDefinition::visible($questions, ['activity' => 'b', 'employer' => 'Acme']);

        $this->assertSame(['activity'], array_column($visible, 'key'));
    }

    public function test_multi_choice_answers_match_when_any_ticked_option_is_listed(): void
    {
        $questions = [
            $this->q('hurdles', ['type' => 'multi_choice', 'options' => [['value' => 'x', 'label' => 'X'], ['value' => 'y', 'label' => 'Y']]]),
            $this->q('more', ['show_if' => ['key' => 'hurdles', 'in' => ['y']]]),
        ];

        $this->assertSame(['hurdles', 'more'], array_column(SurveyDefinition::visible($questions, ['hurdles' => ['x', 'y']]), 'key'));
        $this->assertSame(['hurdles'], array_column(SurveyDefinition::visible($questions, ['hurdles' => ['x']]), 'key'));
    }

    public function test_yes_no_answers_match_booleans(): void
    {
        $questions = [
            $this->q('started', ['type' => 'yes_no']),
            $this->q('workers', ['show_if' => ['key' => 'started', 'in' => [true]]]),
        ];

        $this->assertSame(['started', 'workers'], array_column(SurveyDefinition::visible($questions, ['started' => true]), 'key'));
        $this->assertSame(['started'], array_column(SurveyDefinition::visible($questions, ['started' => false]), 'key'));
    }
}

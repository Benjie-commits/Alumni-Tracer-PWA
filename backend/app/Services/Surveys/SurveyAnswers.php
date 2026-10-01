<?php

namespace App\Services\Surveys;

use App\Models\TracerSurveyVersion;
use Illuminate\Validation\ValidationException;

/**
 * Checks what an alumnus submitted against the exact questionnaire version they were shown.
 *
 * Only questions that apply (given earlier answers) are considered. Answers to unknown or hidden
 * questions are dropped rather than rejected, so a survey that was revised while someone had it open
 * does not lose their whole response.
 */
class SurveyAnswers
{
    private const TEXT_LIMIT = 255;

    private const LONG_TEXT_LIMIT = 2000;

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed> cleaned answers keyed by question key
     *
     * @throws ValidationException with errors keyed "answers.<key>"
     */
    public function validate(TracerSurveyVersion $version, array $raw): array
    {
        $questions = $version->questions();

        // Normalise first, then work out which questions apply from the normalised values.
        $normalised = [];
        $errors = [];
        foreach ($questions as $question) {
            $key = $question['key'];
            if (! array_key_exists($key, $raw) || $this->isEmpty($raw[$key])) {
                continue;
            }

            try {
                $normalised[$key] = $this->normalise($question, $raw[$key]);
            } catch (InvalidAnswer $e) {
                $errors["answers.{$key}"] = $e->getMessage();
            }
        }

        $visible = SurveyDefinition::visible($questions, $normalised);

        // An invalid answer to a question that turned out not to apply is not an error.
        $visibleKeys = array_column($visible, 'key');
        $errors = array_filter($errors, fn ($_, $field) => in_array(substr($field, strlen('answers.')), $visibleKeys, true), ARRAY_FILTER_USE_BOTH);

        $answers = [];
        foreach ($visible as $question) {
            $key = $question['key'];

            if (array_key_exists($key, $normalised)) {
                $answers[$key] = $normalised[$key];
            } elseif (($question['required'] ?? false) && ! isset($errors["answers.{$key}"])) {
                $errors["answers.{$key}"] = 'Please answer this question.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $answers;
    }

    /**
     * Pull the answers that feed dedicated columns out of a validated answer set.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array{employment_status: ?string, further_study_status: ?string, ta_interest: ?bool, started_business: ?bool}
     */
    public function mapped(array $questions, array $answers): array
    {
        $mapped = ['employment_status' => null, 'further_study_status' => null, 'ta_interest' => null, 'started_business' => null];

        foreach ($questions as $question) {
            if (isset($question['maps_to']) && array_key_exists($question['key'], $answers)) {
                $mapped[$question['maps_to']] = $answers[$question['key']];
            }
        }

        return $mapped;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @param  array<string, mixed>  $question */
    private function normalise(array $question, mixed $value): mixed
    {
        return match ($question['type']) {
            'single_choice' => $this->choice($question, $value),
            'multi_choice' => $this->choices($question, $value),
            'text' => $this->text($value, self::TEXT_LIMIT),
            'long_text' => $this->text($value, self::LONG_TEXT_LIMIT),
            'number' => $this->number($question, $value),
            'scale' => $this->scale($question, $value),
            'yes_no' => $this->yesNo($value),
        };
    }

    /** @param  array<string, mixed>  $question */
    private function choice(array $question, mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, $this->optionValues($question), true)) {
            throw new InvalidAnswer('Choose one of the listed options.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    private function choices(array $question, mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidAnswer('Choose from the listed options.');
        }

        $allowed = $this->optionValues($question);
        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                throw new InvalidAnswer('Choose from the listed options.');
            }
        }

        return array_values(array_unique($value));
    }

    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value)) {
            throw new InvalidAnswer('Enter some text.');
        }

        $value = trim($value);
        if (mb_strlen($value) > $limit) {
            throw new InvalidAnswer("Please keep this under {$limit} characters.");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $question */
    private function number(array $question, mixed $value): int|float
    {
        if (! is_numeric($value) || is_bool($value)) {
            throw new InvalidAnswer('Enter a number.');
        }

        $number = $value + 0;
        if (isset($question['min']) && $number < $question['min']) {
            throw new InvalidAnswer("The smallest allowed value is {$question['min']}.");
        }
        if (isset($question['max']) && $number > $question['max']) {
            throw new InvalidAnswer("The largest allowed value is {$question['max']}.");
        }

        return $number;
    }

    /** @param  array<string, mixed>  $question */
    private function scale(array $question, mixed $value): int
    {
        $min = $question['min'] ?? 1;
        $max = $question['max'] ?? 5;

        if (! is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
            throw new InvalidAnswer("Choose a number from {$min} to {$max}.");
        }

        return (int) $value;
    }

    private function yesNo(mixed $value): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'yes', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'no', $value === 'false' => false,
            default => throw new InvalidAnswer('Choose yes or no.'),
        };
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<string>
     */
    private function optionValues(array $question): array
    {
        return array_column($question['options'] ?? [], 'value');
    }
}

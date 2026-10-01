<?php

namespace App\Services\Surveys;

use App\Models\SurveyResponse;

/**
 * Turns stored answers ({"current_activity": "self_employed"}) into what a person would read
 * ("What are you doing now? — Running my own business"), using the questionnaire version that
 * was actually answered, so later wording changes never rewrite history.
 */
final class ReadableAnswers
{
    /**
     * @return list<array{key: string, question: string, answer: string}> in questionnaire order, answered questions only
     */
    public static function for(SurveyResponse $response): array
    {
        $rows = [];

        foreach ($response->version->questions() as $question) {
            if (! array_key_exists($question['key'], $response->answers)) {
                continue;
            }

            $rows[] = [
                'key' => $question['key'],
                'question' => $question['label'],
                'answer' => self::format($question, $response->answers[$question['key']]),
            ];
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $question */
    public static function format(array $question, mixed $value): string
    {
        $labelFor = fn ($v) => collect($question['options'] ?? [])->firstWhere('value', $v)['label'] ?? (string) $v;

        return match ($question['type']) {
            'single_choice' => $labelFor($value),
            'multi_choice' => implode(', ', array_map($labelFor, (array) $value)),
            'yes_no' => $value ? 'Yes' : 'No',
            'scale' => $value.' / '.($question['max'] ?? 5),
            default => (string) $value,
        };
    }
}

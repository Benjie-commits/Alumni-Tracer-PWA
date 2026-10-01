<?php

namespace App\Services\Surveys;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use InvalidArgumentException;

/**
 * Structure rules for a questionnaire, and which of its questions apply given the answers so far.
 * A definition is checked when it is synced, so a typo in config can never reach an alumnus.
 */
final class SurveyDefinition
{
    public const TYPES = ['single_choice', 'multi_choice', 'text', 'long_text', 'number', 'scale', 'yes_no'];

    private const MAPPINGS = ['employment_status', 'further_study_status', 'ta_interest'];

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws InvalidArgumentException naming the first problem found
     */
    public static function assertValid(array $definition): void
    {
        $questions = $definition['questions'] ?? null;
        if (! is_array($questions) || $questions === []) {
            throw new InvalidArgumentException('A survey needs at least one question.');
        }

        $seen = [];
        $mapped = [];

        foreach ($questions as $question) {
            $key = $question['key'] ?? null;
            $where = 'Question '.(is_string($key) ? "'{$key}'" : '(no key)');

            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException("{$where}: keys must be lower-case letters, digits and underscores, starting with a letter.");
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("{$where}: duplicate key.");
            }

            $type = $question['type'] ?? null;
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("{$where}: unknown type '".(is_string($type) ? $type : '?')."'.");
            }
            if (! is_string($question['label'] ?? null) || trim($question['label']) === '') {
                throw new InvalidArgumentException("{$where}: needs a label.");
            }

            if (in_array($type, ['single_choice', 'multi_choice'], true)) {
                self::assertOptions($where, $question['options'] ?? null);
            }

            if (in_array($type, ['number', 'scale'], true)) {
                $min = $question['min'] ?? ($type === 'scale' ? 1 : null);
                $max = $question['max'] ?? ($type === 'scale' ? 5 : null);
                if ($min !== null && $max !== null && $min > $max) {
                    throw new InvalidArgumentException("{$where}: min is greater than max.");
                }
            }

            if (isset($question['show_if'])) {
                $rule = $question['show_if'];
                if (! isset($seen[$rule['key'] ?? ''])) {
                    throw new InvalidArgumentException("{$where}: show_if must refer to a question that comes earlier.");
                }
                if (! is_array($rule['in'] ?? null) || $rule['in'] === []) {
                    throw new InvalidArgumentException("{$where}: show_if needs a non-empty 'in' list.");
                }
            }

            if (isset($question['maps_to'])) {
                self::assertMapping($where, $question, $mapped);
            }

            $seen[$key] = true;
        }
    }

    /**
     * Questions that apply given the answers so far. Order matters: a question can only depend on an
     * earlier one, and an answer to a hidden question is ignored, so a change of mind cannot leave
     * stale answers behind.
     *
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    public static function visible(array $questions, array $answers): array
    {
        $visible = [];
        $effective = [];

        foreach ($questions as $question) {
            if (isset($question['show_if']) && ! self::matches($question['show_if'], $effective)) {
                continue;
            }

            $visible[] = $question;

            if (array_key_exists($question['key'], $answers)) {
                $effective[$question['key']] = $answers[$question['key']];
            }
        }

        return $visible;
    }

    /**
     * @param  array{key: string, in: list<mixed>}  $rule
     * @param  array<string, mixed>  $answers  answers to questions that are themselves visible
     */
    private static function matches(array $rule, array $answers): bool
    {
        if (! array_key_exists($rule['key'], $answers)) {
            return false;
        }

        $given = $answers[$rule['key']];

        // A multi-choice answer matches when any ticked option is listed.
        return is_array($given)
            ? array_intersect($given, $rule['in']) !== []
            : in_array($given, $rule['in'], true);
    }

    private static function assertOptions(string $where, mixed $options): void
    {
        if (! is_array($options) || count($options) < 2) {
            throw new InvalidArgumentException("{$where}: choice questions need at least two options.");
        }

        $values = [];
        foreach ($options as $option) {
            if (! is_string($option['value'] ?? null) || $option['value'] === '' || ! is_string($option['label'] ?? null)) {
                throw new InvalidArgumentException("{$where}: every option needs a text value and label.");
            }
            if (in_array($option['value'], $values, true)) {
                throw new InvalidArgumentException("{$where}: duplicate option value '{$option['value']}'.");
            }
            $values[] = $option['value'];
        }
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, true>  $mapped
     */
    private static function assertMapping(string $where, array $question, array &$mapped): void
    {
        $target = $question['maps_to'];

        if (! in_array($target, self::MAPPINGS, true)) {
            throw new InvalidArgumentException("{$where}: unknown maps_to '{$target}'.");
        }
        if (isset($mapped[$target])) {
            throw new InvalidArgumentException("{$where}: another question already maps to {$target}.");
        }
        $mapped[$target] = true;

        $expected = $target === 'ta_interest' ? 'yes_no' : 'single_choice';
        if ($question['type'] !== $expected) {
            throw new InvalidArgumentException("{$where}: a question mapped to {$target} must be {$expected}.");
        }

        // The mapped columns hold enum values, so the options offered must all be valid ones.
        $allowed = match ($target) {
            'employment_status' => array_map(fn ($c) => $c->value, EmploymentStatus::cases()),
            'further_study_status' => array_map(fn ($c) => $c->value, FurtherStudyStatus::cases()),
            default => null,
        };

        if ($allowed !== null) {
            foreach ($question['options'] as $option) {
                if (! in_array($option['value'], $allowed, true)) {
                    throw new InvalidArgumentException("{$where}: option '{$option['value']}' is not a valid {$target} value.");
                }
            }
        }
    }
}

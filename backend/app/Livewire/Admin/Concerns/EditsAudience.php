<?php

namespace App\Livewire\Admin\Concerns;

use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "who should see this?" part of a job or event form (FR-5): everyone, or one school, department or
 * programme, optionally narrowed to a range of graduation years.
 *
 * The target is one select whose values are "school:3", "department:7", "programme:12" or "" (everyone),
 * so a posting is always aimed at exactly one level and can never carry contradictory ids.
 */
trait EditsAudience
{
    public string $target = '';

    public string $yearFrom = '';

    public string $yearTo = '';

    /** @return array<string, mixed> */
    protected function audienceRules(): array
    {
        $maxYear = (int) date('Y') + 1;

        return [
            'target' => ['nullable', 'string', 'regex:/^(school|department|programme):\d+$/'],
            'yearFrom' => ['nullable', 'integer', "between:1950,{$maxYear}"],
            'yearTo' => ['nullable', 'integer', "between:1950,{$maxYear}"],
        ];
    }

    /**
     * The audience columns to save, after checking the chosen school/department/programme exists.
     *
     * @return array{school_id: ?int, department_id: ?int, programme_id: ?int, graduation_year_from: ?int, graduation_year_to: ?int}
     */
    protected function audienceAttributes(): array
    {
        $columns = ['school_id' => null, 'department_id' => null, 'programme_id' => null];

        if ($this->yearFrom !== '' && $this->yearTo !== '' && (int) $this->yearTo < (int) $this->yearFrom) {
            throw ValidationException::withMessages(['yearTo' => 'The last year must not be before the first.']);
        }

        if ($this->target !== '') {
            [$level, $id] = explode(':', $this->target);
            $model = ['school' => School::class, 'department' => Department::class, 'programme' => Programme::class][$level];

            if (! $model::query()->whereKey((int) $id)->exists()) {
                throw ValidationException::withMessages(['target' => 'That group no longer exists. Choose again.']);
            }
            $columns["{$level}_id"] = (int) $id;
        }

        return $columns + [
            'graduation_year_from' => $this->yearFrom === '' ? null : (int) $this->yearFrom,
            'graduation_year_to' => $this->yearTo === '' ? null : (int) $this->yearTo,
        ];
    }

    protected function fillAudience(Model $posting): void
    {
        $this->target = match (true) {
            (bool) $posting->programme_id => "programme:{$posting->programme_id}",
            (bool) $posting->department_id => "department:{$posting->department_id}",
            (bool) $posting->school_id => "school:{$posting->school_id}",
            default => '',
        };
        $this->yearFrom = (string) ($posting->graduation_year_from ?? '');
        $this->yearTo = (string) ($posting->graduation_year_to ?? '');
    }

    protected function resetAudience(): void
    {
        $this->target = '';
        $this->yearFrom = '';
        $this->yearTo = '';
    }

    /** Options for the target select. */
    protected function audienceOptions(): array
    {
        return [
            'schools' => School::query()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->with('school:id,name')->orderBy('name')->get(['id', 'name', 'school_id']),
            'programmes' => Programme::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}

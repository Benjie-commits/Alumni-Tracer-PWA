<?php

namespace App\Services\Outcomes;

/**
 * The slice of graduates an outcome report covers. Built from request input with every value
 * checked, so a hand-edited URL cannot ask for something the report does not understand.
 */
final readonly class OutcomeFilters
{
    /** Survey answers (the tracer study proper), or the latest status alumni recorded on their own profile. */
    public const SOURCES = ['surveys', 'profiles'];

    public const GROUPS = ['school' => 'School', 'department' => 'Department', 'programme' => 'Programme', 'year' => 'Graduation year'];

    public const MILESTONES = [6, 12, 36];

    public function __construct(
        public string $source = 'surveys',
        /** Surveys only: 6, 12 or 36 months after graduation. Null = each alumnus's most recent answer. */
        public ?int $milestone = null,
        public ?int $schoolId = null,
        public ?int $departmentId = null,
        public ?int $programmeId = null,
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public string $groupBy = 'school',
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromArray(array $input): self
    {
        $int = fn (string $key): ?int => isset($input[$key]) && is_scalar($input[$key]) && ctype_digit((string) $input[$key]) && (string) $input[$key] !== ''
            ? (int) $input[$key]
            : null;

        $source = in_array($input['source'] ?? null, self::SOURCES, true) ? $input['source'] : 'surveys';
        $milestone = $int('milestone');

        return new self(
            source: $source,
            // Milestones only mean something for surveys.
            milestone: $source === 'surveys' && in_array($milestone, self::MILESTONES, true) ? $milestone : null,
            schoolId: $int('schoolId'),
            departmentId: $int('departmentId'),
            programmeId: $int('programmeId'),
            yearFrom: $int('yearFrom'),
            yearTo: $int('yearTo'),
            groupBy: array_key_exists($input['groupBy'] ?? null, self::GROUPS) ? $input['groupBy'] : 'school',
        );
    }

    /**
     * Only the choices that differ from the defaults, for the export link and shareable URLs.
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            'source' => $this->source !== 'surveys' ? $this->source : null,
            'milestone' => $this->milestone,
            'schoolId' => $this->schoolId,
            'departmentId' => $this->departmentId,
            'programmeId' => $this->programmeId,
            'yearFrom' => $this->yearFrom,
            'yearTo' => $this->yearTo,
            'groupBy' => $this->groupBy !== 'school' ? $this->groupBy : null,
        ], fn ($v) => $v !== null);
    }

    public function groupLabel(): string
    {
        return self::GROUPS[$this->groupBy];
    }
}

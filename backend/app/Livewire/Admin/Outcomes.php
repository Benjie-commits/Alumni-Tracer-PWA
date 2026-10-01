<?php

namespace App\Livewire\Admin;

use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use App\Services\Outcomes\OutcomeFilters;
use App\Services\Outcomes\OutcomeReport;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * FR-4: outcome dashboards for Deans, the QA Directorate and Top Management. Aggregates only, so
 * every staff role may open it (QA/Dean viewers included); it never shows an individual's answers.
 */
#[Layout('layouts::admin')]
#[Title('Graduate outcomes')]
class Outcomes extends Component
{
    // Strings throughout: an empty <select> submits '' and typed ?int properties reject that.
    #[Url]
    public string $source = 'surveys';

    #[Url]
    public string $milestone = '';

    #[Url]
    public string $groupBy = 'school';

    #[Url]
    public string $schoolId = '';

    #[Url]
    public string $departmentId = '';

    #[Url]
    public string $programmeId = '';

    #[Url]
    public string $yearFrom = '';

    #[Url]
    public string $yearTo = '';

    public function updatedSchoolId(): void
    {
        $this->departmentId = '';
        $this->programmeId = '';
    }

    public function updatedDepartmentId(): void
    {
        $this->programmeId = '';
    }

    public function clearFilters(): void
    {
        $this->reset('milestone', 'schoolId', 'departmentId', 'programmeId', 'yearFrom', 'yearTo');
    }

    private function filters(): OutcomeFilters
    {
        return OutcomeFilters::fromArray([
            'source' => $this->source, 'milestone' => $this->milestone, 'groupBy' => $this->groupBy,
            'schoolId' => $this->schoolId, 'departmentId' => $this->departmentId, 'programmeId' => $this->programmeId,
            'yearFrom' => $this->yearFrom, 'yearTo' => $this->yearTo,
        ]);
    }

    public function render(OutcomeReport $report)
    {
        $filters = $this->filters();

        return view('livewire.admin.outcomes', [
            'report' => $report->build($filters),
            'exportUrl' => route('admin.outcomes.export', $filters->toQuery()),
            'schools' => School::orderBy('name')->get(['id', 'name']),
            'departments' => $filters->schoolId ? Department::where('school_id', $filters->schoolId)->orderBy('name')->get(['id', 'name']) : collect(),
            'programmes' => Programme::query()
                ->when($filters->departmentId, fn ($q, $id) => $q->where('department_id', $id))
                ->when(! $filters->departmentId && $filters->schoolId, fn ($q) => $q->whereIn('department_id', Department::select('id')->where('school_id', $filters->schoolId)))
                ->orderBy('name')->get(['id', 'name']),
            'years' => AlumniProfile::query()->whereNotNull('graduation_year')->distinct()->orderByDesc('graduation_year')->pluck('graduation_year'),
            'groups' => OutcomeFilters::GROUPS,
            'categories' => OutcomeReport::EMPLOYMENT,
        ]);
    }
}

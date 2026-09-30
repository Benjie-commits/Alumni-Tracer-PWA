<?php

namespace App\Livewire\Admin;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Department;
use App\Models\Programme;
use App\Models\School;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
#[Title('Alumni directory')]
class AlumniDirectory extends Component
{
    use WithPagination;

    // Strings, not ints: an empty <select> submits '' and typed ?int properties reject that.
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $schoolId = '';

    #[Url]
    public string $departmentId = '';

    #[Url]
    public string $programmeId = '';

    #[Url]
    public string $year = '';

    #[Url]
    public string $status = '';

    public function updated(string $property): void
    {
        $this->resetPage();
    }

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
        $this->reset('search', 'schoolId', 'departmentId', 'programmeId', 'year', 'status');
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'pagination.admin';
    }

    /** Query-string form of the active filters, shared with the CSV export link. */
    private function activeFilters(): array
    {
        return array_filter([
            'q' => $this->search,
            'schoolId' => $this->schoolId,
            'departmentId' => $this->departmentId,
            'programmeId' => $this->programmeId,
            'year' => $this->year,
            'status' => $this->status,
        ], fn ($v) => $v !== '');
    }

    public function render()
    {
        $status = VerificationStatus::tryFrom($this->status);

        $profiles = AlumniProfile::query()
            ->with('programme.department.school')
            ->search($this->search, auth()->user()->canManageRecords())
            ->filter([
                'school_id' => $this->schoolId ?: null,
                'department_id' => $this->departmentId ?: null,
                'programme_id' => $this->programmeId ?: null,
                'graduation_year' => $this->year ?: null,
                'verification_status' => $status?->value,
            ])
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate(25);

        $programmes = Programme::query()
            ->when($this->departmentId, fn ($q, $id) => $q->where('department_id', $id))
            ->when(! $this->departmentId && $this->schoolId, fn ($q) => $q->whereIn(
                'department_id', Department::query()->select('id')->where('school_id', $this->schoolId)
            ))
            ->orderBy('name')->get(['id', 'name']);

        return view('livewire.admin.alumni-directory', [
            'profiles' => $profiles,
            'schools' => School::orderBy('name')->get(['id', 'name']),
            'departments' => $this->schoolId
                ? Department::where('school_id', $this->schoolId)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'programmes' => $programmes,
            'years' => AlumniProfile::query()->whereNotNull('graduation_year')->distinct()->orderByDesc('graduation_year')->pluck('graduation_year'),
            'statuses' => VerificationStatus::cases(),
            'exportUrl' => route('admin.alumni.export', $this->activeFilters()),
            'showContact' => auth()->user()->canManageRecords(),
        ]);
    }
}

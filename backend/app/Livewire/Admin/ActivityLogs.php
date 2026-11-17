<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** ICT: the staff audit trail. Who edited, decided, imported or exported what, and when. */
#[Layout('layouts::admin')]
#[Title('Activity log')]
class ActivityLogs extends Component
{
    use AuthorizesStaff, WithPagination;

    #[Url]
    public string $action = '';

    #[Url]
    public string $staff = '';

    public function mount(): void
    {
        $this->authorizeIctAdmin();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['action', 'staff'], true)) {
            $this->resetPage();
        }
    }

    public function paginationView(): string
    {
        return 'pagination.admin';
    }

    public function render()
    {
        $this->authorizeIctAdmin();

        $labels = config('sunates.audit.labels');

        return view('livewire.admin.activity-logs', [
            'logs' => ActivityLog::query()
                ->with('user:id,name')
                ->when(array_key_exists($this->action, $labels), fn ($q) => $q->where('action', $this->action))
                ->when(ctype_digit($this->staff), fn ($q) => $q->where('user_id', (int) $this->staff))
                ->latest('id')
                ->paginate(40),
            'labels' => $labels,
            'staffUsers' => User::query()->whereIn('id', ActivityLog::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'retentionDays' => (int) config('sunates.audit.retention_days'),
        ]);
    }
}

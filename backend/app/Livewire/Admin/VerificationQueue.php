<?php

namespace App\Livewire\Admin;

use App\Enums\VerificationStatus;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\AlumniProfile;
use App\Services\VerificationService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Verification queue')]
class VerificationQueue extends Component
{
    use AuthorizesStaff;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function approve(int $profileId, VerificationService $verification): void
    {
        $this->authorizeManager();

        $verification->approve($this->pending($profileId), auth()->user());
        $this->notice = 'Approved as a new alumni record.';
    }

    public function reject(int $profileId, VerificationService $verification): void
    {
        $this->authorizeManager();

        $verification->reject($this->pending($profileId), auth()->user());
        $this->notice = 'Rejected.';
    }

    public function link(int $profileId, int $recordId, VerificationService $verification): void
    {
        $this->authorizeManager();

        $verification->linkToRecord($this->pending($profileId), AlumniProfile::query()->findOrFail($recordId), auth()->user());
        $this->notice = 'Linked to the Registrar record and verified.';
    }

    private function pending(int $profileId): AlumniProfile
    {
        return AlumniProfile::query()
            ->where('verification_status', VerificationStatus::Pending)
            ->findOrFail($profileId);
    }

    public function render(VerificationService $verification)
    {
        $this->authorizeManager();

        $queue = AlumniProfile::query()
            ->where('verification_status', VerificationStatus::Pending)
            ->with('programme.department.school', 'user')
            ->orderBy('claimed_at')
            ->limit(50)
            ->get();

        return view('livewire.admin.verification-queue', [
            'queue' => $queue,
            'candidates' => $queue->mapWithKeys(fn (AlumniProfile $p) => [$p->id => $verification->candidateRecords($p)]),
        ]);
    }
}

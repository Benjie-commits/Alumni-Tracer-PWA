<?php

namespace App\Livewire\Admin;

use App\Enums\VerificationStatus;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\AlumniProfile;
use App\Services\VerificationService;
use App\Support\Audit;
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

        $official = $verification->approve($profile = $this->pending($profileId), auth()->user());
        Audit::record('verification.approved', $profile);
        $this->dispatch('counts-changed');
        $this->notice = $official
            ? "Approved. {$profile->full_name} is now a verified graduate with student number {$profile->student_number}, so employers can confirm their degree."
            : "Approved, but {$profile->full_name}'s claimed student number is missing, not in the university format, or already used by another record, "
              .'so employers cannot confirm this degree yet. Open the record in the Alumni directory and enter the official student number.';
    }

    public function reject(int $profileId, VerificationService $verification): void
    {
        $this->authorizeManager();

        $verification->reject($profile = $this->pending($profileId), auth()->user());
        Audit::record('verification.rejected', $profile);
        $this->dispatch('counts-changed');
        $this->notice = 'Rejected.';
    }

    public function link(int $profileId, int $recordId, VerificationService $verification): void
    {
        $this->authorizeManager();

        $record = AlumniProfile::query()->findOrFail($recordId);
        $verification->linkToRecord($this->pending($profileId), $record, auth()->user());
        Audit::record('verification.linked', $record, meta: ['claim_id' => $profileId]);
        $this->dispatch('counts-changed');
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

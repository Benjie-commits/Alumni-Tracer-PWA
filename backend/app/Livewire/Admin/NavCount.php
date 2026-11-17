<?php

namespace App\Livewire\Admin;

use App\Enums\EscalationStatus;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\VerificationEscalation;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The little number badges in the sidebar. They live outside the page component, so pages that
 * change the counts dispatch "counts-changed" and the badge redraws at once (no stale numbers).
 */
class NavCount extends Component
{
    public string $kind = 'pending';

    #[On('counts-changed')]
    public function refresh(): void
    {
        // Re-rendering is enough: render() reads the current number.
    }

    public function render()
    {
        $n = match ($this->kind) {
            'enquiries' => VerificationEscalation::where('status', EscalationStatus::Open)->count(),
            default => AlumniProfile::where('verification_status', VerificationStatus::Pending)->count(),
        };

        return view('livewire.admin.nav-count', ['n' => $n]);
    }
}

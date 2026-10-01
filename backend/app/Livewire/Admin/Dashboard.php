<?php

namespace App\Livewire\Admin;

use App\Enums\SurveyInvitationStatus;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Programme;
use App\Models\SurveyInvitation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        $byStatus = AlumniProfile::query()
            ->selectRaw('verification_status, count(*) as n')
            ->groupBy('verification_status')
            ->pluck('n', 'verification_status');

        $count = fn (VerificationStatus $status) => (int) ($byStatus[$status->value] ?? 0);

        return view('livewire.admin.dashboard', [
            'total' => (int) $byStatus->sum(),
            'verified' => $count(VerificationStatus::Verified),
            'unclaimed' => $count(VerificationStatus::Unclaimed),
            'pending' => $count(VerificationStatus::Pending),
            // "Fresh" = the alumnus themselves updated their record in the last 12 months; the same test drives the FR-7 nudges.
            'fresh' => AlumniProfile::query()->where('profile_updated_at', '>=', now()->subYear())->count(),
            'surveysSent' => SurveyInvitation::query()->count(),
            'surveysDone' => SurveyInvitation::query()->where('status', SurveyInvitationStatus::Completed)->count(),
            'taCandidates' => AlumniProfile::query()->taCandidates()->count(),
            'programmes' => Programme::count(),
            'byYear' => AlumniProfile::query()
                ->selectRaw('graduation_year, count(*) as n')
                ->whereNotNull('graduation_year')
                ->groupBy('graduation_year')
                ->orderByDesc('graduation_year')
                ->limit(8)
                ->get(),
        ]);
    }
}

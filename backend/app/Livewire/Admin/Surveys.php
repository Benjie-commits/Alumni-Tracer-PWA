<?php

namespace App\Livewire\Admin;

use App\Enums\SurveyInvitationStatus;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\SurveyInvitation;
use App\Models\TracerSurveyCycle;
use App\Services\Surveys\SurveyScheduler;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Tracer surveys')]
class Surveys extends Component
{
    use AuthorizesStaff;

    public function toggleActive(int $cycleId): void
    {
        $this->authorizeManager();

        $cycle = TracerSurveyCycle::query()->findOrFail($cycleId);
        $cycle->update(['is_active' => ! $cycle->is_active]);
    }

    public function render(SurveyScheduler $scheduler)
    {
        $cycles = TracerSurveyCycle::query()->with('currentVersion')->orderBy('milestone_months')->get();

        $counts = SurveyInvitation::query()
            ->selectRaw('tracer_survey_cycle_id, status, count(*) as n')
            ->groupBy('tracer_survey_cycle_id', 'status')
            ->get()
            ->groupBy('tracer_survey_cycle_id');

        $dueToday = $scheduler->schedule(dryRun: true);

        return view('livewire.admin.surveys', [
            'rows' => $cycles->map(function (TracerSurveyCycle $cycle) use ($counts, $scheduler, $dueToday) {
                // status is an enum-cast attribute, so it must be turned into its string value before it can be an array key.
                $byStatus = ($counts[$cycle->id] ?? collect())->mapWithKeys(fn ($r) => [$r->status->value => (int) $r->n]);
                $count = fn (SurveyInvitationStatus $s) => (int) ($byStatus[$s->value] ?? 0);
                $total = (int) $byStatus->sum();

                return [
                    'cycle' => $cycle,
                    'total' => $total,
                    'sent' => $count(SurveyInvitationStatus::Sent),
                    'scheduled' => $count(SurveyInvitationStatus::Scheduled),
                    'completed' => $count(SurveyInvitationStatus::Completed),
                    'expired' => $count(SurveyInvitationStatus::Expired),
                    'rate' => $total > 0 ? round($count(SurveyInvitationStatus::Completed) / $total * 100) : null,
                    'dueToday' => $dueToday[$cycle->milestone_months] ?? 0,
                    'upcoming' => $scheduler->upcomingCount($cycle, 30),
                ];
            }),
            'canManage' => auth()->user()->canManageRecords(),
        ]);
    }
}

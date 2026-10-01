<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyCycle;
use App\Services\Surveys\ReadableAnswers;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
#[Title('Survey responses')]
class SurveyResponses extends Component
{
    use AuthorizesStaff, WithPagination;

    public TracerSurveyCycle $cycle;

    /** The response whose answers are expanded. */
    public ?int $openId = null;

    public function mount(TracerSurveyCycle $cycle): void
    {
        // Individual responses are personal data: Registrar and ICT only (QA sees aggregates in Phase 3).
        $this->authorizeManager();
        $this->cycle = $cycle;
    }

    public function toggle(int $responseId): void
    {
        $this->authorizeManager();
        $this->openId = $this->openId === $responseId ? null : $responseId;
    }

    public function paginationView(): string
    {
        return 'pagination.admin';
    }

    public function render()
    {
        $this->authorizeManager();

        $responses = SurveyResponse::query()
            ->whereHas('invitation', fn ($q) => $q->where('tracer_survey_cycle_id', $this->cycle->id))
            ->with('profile.programme.department.school')
            ->latest('submitted_at')
            ->paginate(25);

        $open = $this->openId
            ? SurveyResponse::query()->with('version')->find($this->openId)
            : null;

        return view('livewire.admin.survey-responses', [
            'responses' => $responses,
            // Answers are shown with the wording of the version that was answered, not today's.
            'openAnswers' => $open ? ReadableAnswers::for($open) : [],
            'openId' => $open?->id,
        ]);
    }
}

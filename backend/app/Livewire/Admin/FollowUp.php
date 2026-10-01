<?php

namespace App\Livewire\Admin;

use App\Enums\FollowUpOutcome;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\AlumniProfile;
use App\Models\FollowUpCheck;
use App\Models\Programme;
use App\Services\Followup\FollowUpCandidates;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Alumni who never answer us, listed for the occasional manual search the spec allows in place of
 * automated LinkedIn monitoring (section 7.4). It shows personal details, so it is Registrar and ICT only.
 */
#[Layout('layouts::admin')]
#[Title('Follow-up list')]
class FollowUp extends Component
{
    use AuthorizesStaff, WithPagination;

    #[Url]
    public string $tab = FollowUpCandidates::UNRESPONSIVE;

    #[Url]
    public string $programme = '';

    #[Url]
    public string $year = '';

    #[Url]
    public string $q = '';

    /** @var array<int, string> what each person's check found, typed per row */
    public array $outcome = [];

    /** @var array<int, string> */
    public array $note = [];

    public ?string $recorded = null;

    public function mount(): void
    {
        $this->authorizeManager();
        $this->tab = $this->tab === FollowUpCandidates::UNREACHABLE ? FollowUpCandidates::UNREACHABLE : FollowUpCandidates::UNRESPONSIVE;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'programme', 'year', 'q'], true)) {
            $this->resetPage();
        }
    }

    public function paginationView(): string
    {
        return 'pagination.admin';
    }

    /** Record that someone looked for this alumnus and what they found. */
    public function record(int $profileId): void
    {
        $this->authorizeManager();

        $this->validate([
            "outcome.{$profileId}" => ['required', Rule::enum(FollowUpOutcome::class)],
            "note.{$profileId}" => ['nullable', 'string', 'max:500'],
        ], [
            "outcome.{$profileId}.required" => 'Say what you found.',
        ]);

        $profile = AlumniProfile::query()->findOrFail($profileId);

        FollowUpCheck::create([
            'alumni_profile_id' => $profile->id,
            'checked_by' => auth()->id(),
            'outcome' => $this->outcome[$profileId],
            'note' => ($this->note[$profileId] ?? '') !== '' ? trim($this->note[$profileId]) : null,
        ]);

        unset($this->outcome[$profileId], $this->note[$profileId]);
        $this->recorded = $profile->full_name.' is recorded as checked. They will not be suggested again for '.config('sunates.followup.recheck_after_days').' days.';
    }

    public function render(FollowUpCandidates $candidates)
    {
        $this->authorizeManager();

        $filters = ['programme_id' => $this->programme, 'graduation_year' => $this->year, 'search' => $this->q];
        $reason = $this->tab === FollowUpCandidates::UNREACHABLE ? FollowUpCandidates::UNREACHABLE : FollowUpCandidates::UNRESPONSIVE;

        return view('livewire.admin.follow-up', [
            'people' => $candidates->withNudgeCount($candidates->query($reason, $filters))->with('programme')->paginate(25),
            'unresponsiveCount' => $candidates->query(FollowUpCandidates::UNRESPONSIVE)->count(),
            'unreachableCount' => $candidates->query(FollowUpCandidates::UNREACHABLE)->count(),
            'programmes' => Programme::query()->orderBy('name')->get(['id', 'name']),
            'years' => AlumniProfile::query()->whereNotNull('graduation_year')->distinct()->orderByDesc('graduation_year')->pluck('graduation_year'),
            'outcomes' => FollowUpOutcome::cases(),
        ]);
    }
}

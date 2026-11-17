<?php

namespace App\Livewire\Admin;

use App\Enums\JobType;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Livewire\Admin\Concerns\EditsAudience;
use App\Models\JobPosting;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** FR-5: job and internship postings, aimed at the alumni they suit. Registrar and ICT staff. */
#[Layout('layouts::admin')]
#[Title('Jobs and internships')]
class JobPostings extends Component
{
    use AuthorizesStaff, EditsAudience, WithPagination;

    public ?int $editingId = null;

    public string $title = '';

    public string $organisation = '';

    public string $type = 'job';

    public string $location = '';

    public string $description = '';

    public string $howToApply = '';

    public string $closesOn = '';

    public bool $publish = true;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function paginationView(): string
    {
        return 'pagination.admin';
    }

    public function save(): void
    {
        $this->authorizeManager();
        $this->notice = null;

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'organisation' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(JobType::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'howToApply' => ['required', 'string', 'max:500'],
            'closesOn' => ['nullable', 'date'],
            ...$this->audienceRules(),
        ], [
            'howToApply.required' => 'Say how to apply: a link, an email address or instructions.',
        ]);

        $posting = $this->editingId ? JobPosting::query()->findOrFail($this->editingId) : new JobPosting(['created_by' => auth()->id()]);

        $posting->fill([
            'title' => $this->title,
            'organisation' => $this->organisation,
            'type' => $this->type,
            'location' => $this->location ?: null,
            'description' => $this->description,
            'how_to_apply' => $this->howToApply,
            'closes_on' => $this->closesOn ?: null,
            ...$this->audienceAttributes(),
            // Keep the original publication date when re-saving a published posting.
            'published_at' => $this->publish ? ($posting->published_at ?? now()) : null,
        ])->save();

        $this->notice = $this->publish ? 'Saved and published: the alumni it is aimed at see it in the app.' : 'Saved as a draft: alumni cannot see it yet.';
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $this->authorizeManager();
        $posting = JobPosting::query()->findOrFail($id);

        $this->editingId = $posting->id;
        $this->title = $posting->title;
        $this->organisation = $posting->organisation;
        $this->type = $posting->type->value;
        $this->location = (string) $posting->location;
        $this->description = $posting->description;
        $this->howToApply = $posting->how_to_apply;
        $this->closesOn = (string) $posting->closes_on?->toDateString();
        $this->publish = $posting->published_at !== null;
        $this->fillAudience($posting);
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function togglePublished(int $id): void
    {
        $this->authorizeManager();
        $posting = JobPosting::query()->findOrFail($id);
        $posting->update(['published_at' => $posting->published_at ? null : now()]);
    }

    public function delete(int $id): void
    {
        $this->authorizeManager();
        JobPosting::query()->findOrFail($id)->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'title', 'organisation', 'type', 'location', 'description', 'howToApply', 'closesOn', 'publish');
        $this->resetAudience();
        $this->resetErrorBag();
    }

    public function render()
    {
        $this->authorizeManager();

        return view('livewire.admin.job-postings', [
            'postings' => JobPosting::query()->with(['school', 'department', 'programme'])->latest('id')->paginate(20),
            'types' => JobType::cases(),
            'today' => now(config('sunates.timezone'))->startOfDay(),
            ...$this->audienceOptions(),
        ]);
    }
}

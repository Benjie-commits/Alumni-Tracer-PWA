<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Livewire\Admin\Concerns\EditsAudience;
use App\Models\EventPosting;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** FR-5: event notices (reunions, career fairs, guest lectures), aimed at the alumni they concern. */
#[Layout('layouts::admin')]
#[Title('Events')]
class EventPostings extends Component
{
    use AuthorizesStaff, EditsAudience, WithPagination;

    public ?int $editingId = null;

    public string $title = '';

    public string $description = '';

    /** "Y-m-d\TH:i", Uganda time, as an <input type="datetime-local"> gives it. */
    public string $startsAt = '';

    public string $endsAt = '';

    public string $venue = '';

    public string $onlineUrl = '';

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
            'description' => ['required', 'string', 'max:5000'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'venue' => ['nullable', 'string', 'max:255', 'required_without:onlineUrl'],
            'onlineUrl' => ['nullable', 'url:https,http', 'max:500'],
            ...$this->audienceRules(),
        ], [
            'endsAt.after' => 'The event must end after it starts.',
            'venue.required_without' => 'Give a venue, an online link, or both.',
        ]);

        $posting = $this->editingId ? EventPosting::query()->findOrFail($this->editingId) : new EventPosting(['created_by' => auth()->id()]);

        $posting->fill([
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->local($this->startsAt),
            'ends_at' => $this->endsAt === '' ? null : $this->local($this->endsAt),
            'venue' => $this->venue ?: null,
            'online_url' => $this->onlineUrl ?: null,
            ...$this->audienceAttributes(),
            'published_at' => $this->publish ? ($posting->published_at ?? now()) : null,
        ])->save();

        $this->notice = $this->publish ? 'Saved and published: the alumni it is aimed at see it in the app.' : 'Saved as a draft: alumni cannot see it yet.';
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $this->authorizeManager();
        $posting = EventPosting::query()->findOrFail($id);
        $tz = config('sunates.timezone');

        $this->editingId = $posting->id;
        $this->title = $posting->title;
        $this->description = $posting->description;
        $this->startsAt = $posting->starts_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i');
        $this->endsAt = (string) $posting->ends_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i');
        $this->venue = (string) $posting->venue;
        $this->onlineUrl = (string) $posting->online_url;
        $this->publish = $posting->published_at !== null;
        $this->fillAudience($posting);
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function togglePublished(int $id): void
    {
        $this->authorizeManager();
        $posting = EventPosting::query()->findOrFail($id);
        $posting->update(['published_at' => $posting->published_at ? null : now()]);
    }

    public function delete(int $id): void
    {
        $this->authorizeManager();
        EventPosting::query()->findOrFail($id)->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }
    }

    public function resetForm(): void
    {
        $this->reset('editingId', 'title', 'description', 'startsAt', 'endsAt', 'venue', 'onlineUrl', 'publish');
        $this->resetAudience();
        $this->resetErrorBag();
    }

    /** Times are typed in Uganda time and stored in UTC like every other timestamp. */
    private function local(string $value): Carbon
    {
        return Carbon::parse($value, config('sunates.timezone'))->utc();
    }

    public function render()
    {
        $this->authorizeManager();

        return view('livewire.admin.event-postings', [
            'postings' => EventPosting::query()->with(['school', 'department', 'programme'])
                ->withCount([
                    'rsvps as going_count' => fn ($q) => $q->where('response', 'going'),
                    'rsvps as maybe_count' => fn ($q) => $q->where('response', 'maybe'),
                ])->orderByDesc('starts_at')->paginate(20),
            'tz' => config('sunates.timezone'),
            ...$this->audienceOptions(),
        ]);
    }
}

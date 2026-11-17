<div>
    <div class="head">
        <div>
            <h1>Jobs and internships</h1>
            <span class="muted">Opportunities shown in the alumni app, only to the alumni each one is aimed at. Nothing is sent by SMS or WhatsApp.</span>
        </div>
    </div>

    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    <form class="panel" wire:submit="save">
        <h2>{{ $editingId ? 'Edit posting' : 'New posting' }}</h2>
        <div class="grid2">
            <label class="field"><span>Title</span><input type="text" wire:model="title" placeholder="e.g. Graduate trainee, agronomy">@error('title')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Organisation</span><input type="text" wire:model="organisation">@error('organisation')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Type</span>
                <select wire:model="type">@foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select>
                @error('type')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Location (optional)</span><input type="text" wire:model="location" placeholder="e.g. Soroti, or Remote">@error('location')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Applications close (optional)</span><input type="date" wire:model="closesOn">@error('closesOn')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>How to apply</span><input type="text" wire:model="howToApply" placeholder="A link, an email address, or instructions">@error('howToApply')<span class="error">{{ $message }}</span>@enderror</label>
        </div>
        <label class="field" style="margin-top:14px"><span>Description</span>
            <textarea wire:model="description" rows="5"></textarea>
            @error('description')<span class="error">{{ $message }}</span>@enderror</label>

        <h2 style="margin-top:18px">Audience</h2>
        <div class="grid3">
            @include('livewire.admin.partials.audience')
        </div>

        <div class="actions">
            <label><input type="checkbox" wire:model="publish"> Publish now (untick to save a draft)</label>
            <button type="submit">{{ $editingId ? 'Save changes' : 'Add posting' }}</button>
            @if ($editingId)<button type="button" class="secondary" wire:click="resetForm">Cancel</button>@endif
        </div>
    </form>

    <div class="panel">
        <table>
            <thead><tr><th>Posting</th><th>Type</th><th>Aimed at</th><th>Closes</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($postings as $posting)
                <tr wire:key="j{{ $posting->id }}">
                    <td><strong>{{ $posting->title }}</strong><br><span class="muted small">{{ $posting->organisation }}{{ $posting->location ? ' · '.$posting->location : '' }}</span></td>
                    <td>{{ $posting->type->label() }}</td>
                    <td class="small">{{ $posting->audienceLabel() }}</td>
                    <td class="small">{{ $posting->closes_on?->format('j M Y') ?? '—' }}</td>
                    <td>
                        @if (! $posting->published_at)
                            <span class="pill">Draft</span>
                        @elseif ($posting->closes_on && $posting->closes_on->lt($today))
                            <span class="pill rejected">Closed</span>
                        @else
                            <span class="pill verified">Published</span>
                        @endif
                    </td>
                    <td style="white-space:nowrap">
                        <button type="button" class="secondary" wire:click="edit({{ $posting->id }})">Edit</button>
                        <button type="button" class="secondary" wire:click="togglePublished({{ $posting->id }})">{{ $posting->published_at ? 'Unpublish' : 'Publish' }}</button>
                        <button type="button" class="secondary" wire:click="delete({{ $posting->id }})" wire:confirm="Delete “{{ $posting->title }}”?">Delete</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No postings yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        {{ $postings->links() }}
    </div>
</div>

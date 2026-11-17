<div>
    <div class="head">
        <div>
            <h1>Events</h1>
            <span class="muted">Event notices shown in the alumni app, only to the alumni each one is aimed at. Times are Uganda time.</span>
        </div>
    </div>

    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    <form class="panel" wire:submit="save">
        <h2>{{ $editingId ? 'Edit event' : 'New event' }}</h2>
        <div class="grid2">
            <label class="field"><span>Title</span><input type="text" wire:model="title" placeholder="e.g. Alumni homecoming 2026">@error('title')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Venue (optional if online)</span><input type="text" wire:model="venue">@error('venue')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Starts</span><input type="datetime-local" wire:model="startsAt">@error('startsAt')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Ends (optional)</span><input type="datetime-local" wire:model="endsAt">@error('endsAt')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Online link (optional)</span><input type="url" wire:model="onlineUrl" placeholder="https://…">@error('onlineUrl')<span class="error">{{ $message }}</span>@enderror</label>
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
            <button type="submit">{{ $editingId ? 'Save changes' : 'Add event' }}</button>
            @if ($editingId)<button type="button" class="secondary" wire:click="resetForm">Cancel</button>@endif
        </div>
    </form>

    <div class="panel">
        <table>
            <thead><tr><th>Event</th><th>When</th><th>Where</th><th>Aimed at</th><th>Coming</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($postings as $posting)
                <tr wire:key="e{{ $posting->id }}">
                    <td><strong>{{ $posting->title }}</strong></td>
                    <td class="small" style="white-space:nowrap">{{ $posting->starts_at->copy()->setTimezone($tz)->format('j M Y, H:i') }}</td>
                    <td class="small">{{ $posting->venue ?? '' }}{{ $posting->venue && $posting->online_url ? ' · ' : '' }}{{ $posting->online_url ? 'Online' : '' }}</td>
                    <td class="small">{{ $posting->audienceLabel() }}</td>
                    <td class="small">{{ $posting->going_count }} going{{ $posting->maybe_count ? ', '.$posting->maybe_count.' maybe' : '' }}</td>
                    <td>
                        @if (! $posting->published_at)
                            <span class="pill">Draft</span>
                        @elseif (($posting->ends_at ?? $posting->starts_at)->isPast())
                            <span class="pill rejected">Over</span>
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
                <tr><td colspan="7" class="empty">No events yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        {{ $postings->links() }}
    </div>
</div>

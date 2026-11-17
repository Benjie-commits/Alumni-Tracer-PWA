{{-- "Who should see this?" for jobs and events (FR-5). Expects $schools, $departments, $programmes. --}}
<label class="field"><span>Who should see this?</span>
    <select wire:model="target">
        <option value="">All alumni</option>
        <optgroup label="A school">
            @foreach ($schools as $s)<option value="school:{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </optgroup>
        <optgroup label="A department">
            @foreach ($departments as $d)<option value="department:{{ $d->id }}">{{ $d->name }} ({{ $d->school?->name }})</option>@endforeach
        </optgroup>
        <optgroup label="A programme">
            @foreach ($programmes as $p)<option value="programme:{{ $p->id }}">{{ $p->name }}</option>@endforeach
        </optgroup>
    </select>
    @error('target')<span class="error">{{ $message }}</span>@enderror
</label>
<label class="field"><span>Graduated from (year, optional)</span>
    <input type="number" wire:model="yearFrom" min="1950" max="{{ date('Y') + 1 }}">
    @error('yearFrom')<span class="error">{{ $message }}</span>@enderror
</label>
<label class="field"><span>Graduated up to (year, optional)</span>
    <input type="number" wire:model="yearTo" min="1950" max="{{ date('Y') + 1 }}">
    @error('yearTo')<span class="error">{{ $message }}</span>@enderror
</label>

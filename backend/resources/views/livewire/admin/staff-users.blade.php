<div>
    <div class="head">
        <div>
            <h1>Staff accounts</h1>
            <span class="muted">Who can sign in to this console. Alumni accounts are created by alumni themselves.</span>
        </div>
    </div>

    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif
    @error('toggle')<div class="alert bad">{{ $message }}</div>@enderror

    <div class="panel">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($staff as $member)
                <tr wire:key="u{{ $member->id }}">
                    <td>{{ $member->name }}</td>
                    <td>{{ $member->email }}</td>
                    <td>{{ $member->role->name }}</td>
                    <td class="muted">{{ $member->last_login_at?->diffForHumans() ?? 'never' }}</td>
                    <td><span class="pill {{ $member->is_active ? 'verified' : 'rejected' }}">{{ $member->is_active ? 'Active' : 'Deactivated' }}</span></td>
                    <td style="white-space:nowrap">
                        @if ($resettingId === $member->id)
                            <input type="password" wire:model="newPassword" placeholder="New password (10+ chars)" autocomplete="new-password">
                            <button type="button" wire:click="savePassword">Save</button>
                            <button type="button" class="secondary" wire:click="$set('resettingId', null)">Cancel</button>
                            @error('newPassword')<div class="error">{{ $message }}</div>@enderror
                        @else
                            <button type="button" class="secondary" wire:click="startReset({{ $member->id }})">Reset password</button>
                            @if ($member->id !== auth()->id())
                                <button type="button" class="secondary" wire:click="toggleActive({{ $member->id }})"
                                        wire:confirm="{{ $member->is_active ? 'Deactivate' : 'Reactivate' }} {{ $member->name }}?">
                                    {{ $member->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <form class="panel" wire:submit="create">
        <h2>Add a staff account</h2>
        <div class="grid2">
            <label class="field"><span>Full name</span><input type="text" wire:model="name">@error('name')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Email</span><input type="email" wire:model="email">@error('email')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Role</span>
                <select wire:model="role">
                    <option value="">Choose…</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
                @error('role')<span class="error">{{ $message }}</span>@enderror</label>
            <label class="field"><span>Temporary password (10+ characters)</span><input type="password" wire:model="password" autocomplete="new-password">@error('password')<span class="error">{{ $message }}</span>@enderror</label>
        </div>
        <div class="actions"><button type="submit">Create account</button></div>
    </form>
</div>

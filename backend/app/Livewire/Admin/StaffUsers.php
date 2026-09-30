<?php

namespace App\Livewire\Admin;

use App\Enums\RoleSlug;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Staff accounts')]
class StaffUsers extends Component
{
    use AuthorizesStaff;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public string $password = '';

    public ?int $resettingId = null;

    public string $newPassword = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeIctAdmin();
    }

    public function create(): void
    {
        $this->authorizeIctAdmin();
        $this->notice = null;

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(array_map(fn (RoleSlug $r) => $r->value, RoleSlug::staff()))],
            'password' => ['required', 'string', Password::min(10)->max(128)],
        ]);

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => Role::idFor(RoleSlug::from($data['role'])),
        ]);

        $this->reset('name', 'email', 'role', 'password');
        $this->notice = "Account created. Share the temporary password with {$data['name']} securely; they should change it after first sign-in.";
    }

    public function toggleActive(int $userId): void
    {
        $this->authorizeIctAdmin();
        $user = $this->staffUser($userId);

        if ($user->id === auth()->id()) {
            throw ValidationException::withMessages(['toggle' => 'You cannot deactivate your own account.']);
        }

        if ($user->is_active && $user->hasRole(RoleSlug::IctAdmin) && $this->activeIctAdmins() <= 1) {
            throw ValidationException::withMessages(['toggle' => 'There must always be at least one active ICT admin.']);
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }
    }

    public function startReset(int $userId): void
    {
        $this->authorizeIctAdmin();

        $this->resettingId = $this->staffUser($userId)->id;
        $this->newPassword = '';
        $this->resetErrorBag();
    }

    public function savePassword(): void
    {
        $this->authorizeIctAdmin();

        $this->validate(['newPassword' => ['required', 'string', Password::min(10)->max(128)]]);

        $user = $this->staffUser((int) $this->resettingId);
        $user->update(['password' => $this->newPassword]);

        $this->notice = "Password reset for {$user->name}.";
        $this->reset('resettingId', 'newPassword');
    }

    private function staffUser(int $userId): User
    {
        return User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', array_map(fn (RoleSlug $r) => $r->value, RoleSlug::staff())))
            ->findOrFail($userId);
    }

    private function activeIctAdmins(): int
    {
        return User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', RoleSlug::IctAdmin->value))->count();
    }

    public function render()
    {
        $this->authorizeIctAdmin();

        return view('livewire.admin.staff-users', [
            'staff' => User::query()
                ->with('role')
                ->whereHas('role', fn ($q) => $q->whereIn('slug', array_map(fn (RoleSlug $r) => $r->value, RoleSlug::staff())))
                ->orderBy('name')->get(),
            'roles' => RoleSlug::staff(),
        ]);
    }
}

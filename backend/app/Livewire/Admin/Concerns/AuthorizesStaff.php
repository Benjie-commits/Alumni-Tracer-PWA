<?php

namespace App\Livewire\Admin\Concerns;

use App\Enums\RoleSlug;

/**
 * Livewire actions are callable by any client that has the page open, so route middleware alone
 * is not enough: every state-changing action re-checks the role.
 */
trait AuthorizesStaff
{
    protected function authorizeManager(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()->canManageRecords(), 403);
    }

    protected function authorizeIctAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()->hasRole(RoleSlug::IctAdmin), 403);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\RoleSlug;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based access control: `role:registrar,ict_admin` admits any of the listed roles.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_filter(array_map(RoleSlug::tryFrom(...), $roles));
        $user = $request->user();

        abort_unless($user && $user->is_active && $user->hasRole(...$allowed), 403, 'You do not have access to this area.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff signed in with a temporary password (set by the ICT admin who created or reset the account)
 * must choose their own before they can use anything else in the dashboard.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs('admin.password.*', 'admin.logout')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Choose a new password first.'], 403)
                : redirect()->route('admin.password.edit');
        }

        return $next($request);
    }
}

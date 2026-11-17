<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Staff sessions for the dashboard (spec section 8). Everyone signs in on the one sign-in page in the
 * app; when the account is staff, the API hands back a single-use link that lands here.
 */
class AuthController extends Controller
{
    /** The old console sign-in address now points at the shared sign-in page. */
    public function create(): RedirectResponse
    {
        return redirect('/app/login');
    }

    public function handoff(Request $request, string $nonce): RedirectResponse
    {
        $userId = Cache::pull('staff-handoff:'.$nonce); // single use
        $user = $userId ? User::find($userId) : null;

        if (! $user || ! $user->is_active || ! $user->isStaff()) {
            return redirect('/app/login');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/app/login');
    }
}

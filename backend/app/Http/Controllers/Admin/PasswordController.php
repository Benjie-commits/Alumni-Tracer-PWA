<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** "Choose your own password": forced after a temporary one, and available any time after that. */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.change-password', ['forced' => (bool) $request->user()->must_change_password]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->max(128)],
        ], [
            'current_password.current_password' => 'That is not your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        if (Hash::check($request->input('password'), $user->password)) {
            return back()->withErrors(['password' => 'Choose a password different from the temporary one.']);
        }

        $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false])->save();
        $request->session()->regenerate();
        Audit::record('staff.password_changed', $user);

        return redirect()->route('admin.dashboard')->with('status', 'Your new password is saved.');
    }
}

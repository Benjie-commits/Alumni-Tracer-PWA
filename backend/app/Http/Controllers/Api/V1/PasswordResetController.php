<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Alumni "forgot my password": a reset link by email (Laravel's password broker; links last 60 minutes
 * and one can be requested per minute per account). Staff passwords are reset by ICT in the console.
 */
class PasswordResetController extends Controller
{
    /** The same answer whether or not the email has an account, so the form cannot be used to find accounts. */
    private const SENT = 'If an alumni account uses that email, we have sent it a link to choose a new password. It works for 60 minutes.';

    public function request(Request $request): JsonResponse
    {
        $email = $request->validate(['email' => ['required', 'email', 'max:255']])['email'];

        $user = User::query()->where('email', $email)->first();

        // Only active alumni accounts; anything else gets the same answer and no email.
        if ($user && $user->is_active && $user->isAlumnus()) {
            // The broker's own throttle (one link a minute per account) is not an error the person needs to see.
            Password::sendResetLink(['email' => $user->email]);
        }

        return response()->json(['message' => self::SENT]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->max(128)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            if (! $user->isAlumnus() || ! $user->is_active) {
                throw ValidationException::withMessages(['email' => 'This link cannot be used to reset this account.']);
            }

            $user->forceFill(['password' => $password])->save();

            // Whoever may have known the old password is signed out on every device.
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }

        return response()->json(['message' => 'Your password has been changed. Sign in with your new password.']);
    }
}

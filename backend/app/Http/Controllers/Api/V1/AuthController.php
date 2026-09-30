<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AlumniRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AlumniRegistrationService $registration): JsonResponse
    {
        $profile = $registration->register($request->validated());
        $user = $profile->user;

        return $this->tokenResponse($user, $request->validated('device_name'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        // Credentials are proven at this point, so it is safe to say why access is refused.
        abort_unless($user->isAlumnus(), 403, 'Staff accounts sign in to the admin dashboard, not the alumni app.');

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($user, $request->validated('device_name'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource(
            $request->user()->load('role', 'alumniProfile.programme.department.school', 'alumniProfile.employmentRecords')
        );
    }

    private function tokenResponse(User $user, ?string $deviceName, int $status = 200): JsonResponse
    {
        $device = $deviceName ?: 'alumni-pwa';

        // One live token per device name, so repeat sign-ins from the same phone do not pile up.
        $user->tokens()->where('name', $device)->delete();

        $user->load('role', 'alumniProfile.programme.department.school', 'alumniProfile.employmentRecords');

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => new UserResource($user),
        ], $status);
    }
}

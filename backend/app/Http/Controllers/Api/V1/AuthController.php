<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\RegistrationChallenge;
use App\Models\User;
use App\Services\AlumniRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * A matching Registrar record gets a code sent to its contact details (202, nothing created yet);
     * anything else is created straight away as a claim for the Registrar to review (201, signed in).
     */
    public function register(RegisterRequest $request, AlumniRegistrationService $registration): JsonResponse
    {
        $result = $registration->register($request->validated(), $request->ip());

        if ($result instanceof RegistrationChallenge) {
            return $this->challengeResponse($result);
        }

        return $this->tokenResponse($result->user, $request->validated('device_name'), 201);
    }

    /** Step two: the code from the phone or email on record. Claims the record and signs in. */
    public function verifyCode(Request $request, AlumniRegistrationService $registration): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ], ['code.regex' => 'Enter the 6-digit code we sent you.']);

        $profile = $registration->verifyCode($data['challenge'], $data['code']);

        return $this->tokenResponse($profile->user, $data['device_name'] ?? null, 201);
    }

    public function resendCode(Request $request, AlumniRegistrationService $registration): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:64']]);

        return $this->challengeResponse($registration->resendCode($data['challenge']));
    }

    private function challengeResponse(RegistrationChallenge $challenge): JsonResponse
    {
        return response()->json(['data' => [
            'status' => 'code_sent',
            'challenge' => $challenge->token,
            'channel' => $challenge->channel,
            'sent_to' => $challenge->destination_hint,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_after_seconds' => (int) config('sunates.registration.resend_after_seconds'),
        ]], 202);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        // Hash something even when the email is unknown, so the response time does not reveal which
        // addresses have accounts (the staff console gets the same protection from Auth::attempt).
        $passwordOk = Hash::check($request->validated('password'), $user?->password ?? self::dummyHash());

        if (! $user || ! $passwordOk || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        // Credentials are proven at this point, so it is safe to say why access is refused.
        // One sign-in page for everyone: staff get a single-use, 60-second hand-off link that opens a
        // dashboard session (the API itself never issues tokens to staff).
        if ($user->isStaff()) {
            $nonce = Str::random(64);
            Cache::put('staff-handoff:'.$nonce, $user->id, 60);

            return response()->json(['data' => [
                'status' => 'staff',
                'redirect' => url('/admin/handoff/'.$nonce),
            ]]);
        }

        abort_unless($user->isAlumnus() || $user->isStudent(), 403, 'This account cannot sign in here.');

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
            $request->user()->load('role', 'alumniProfile.programme.department.school', 'alumniProfile.employmentRecords', 'student.programme')
        );
    }

    /** A hash of nothing in particular, made with the current cost settings, to compare unknown emails against. */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('sunates-timing-equaliser');
    }

    private function tokenResponse(User $user, ?string $deviceName, int $status = 200): JsonResponse
    {
        $device = $deviceName ?: 'alumni-pwa';

        // One live token per device name, so repeat sign-ins from the same phone do not pile up.
        $user->tokens()->where('name', $device)->delete();

        $user->load('role', 'alumniProfile.programme.department.school', 'alumniProfile.employmentRecords', 'student.programme');

        return response()->json([
            'token' => $user->createToken($device)->plainTextToken,
            'user' => new UserResource($user),
        ], $status);
    }
}

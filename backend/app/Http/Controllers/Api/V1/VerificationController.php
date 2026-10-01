<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VerificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\LookupRequest;
use App\Services\Verification\CredentialVerifier;
use Illuminate\Http\JsonResponse;

/**
 * The same lookup as the web portal, for partner institutions that want to call it from their own
 * systems. Unauthenticated but rate-limited (spec section 9); returns graduation status, programme
 * and year only, never contact details.
 */
class VerificationController extends Controller
{
    public function lookup(LookupRequest $request, CredentialVerifier $verifier): JsonResponse
    {
        $outcome = $verifier->lookup(
            $request->validated('name'),
            $request->validated('programme_id'),
            $request->validated('graduation_year'),
            $request->validated('organisation'),
            $request->validated('email'),
            VerificationChannel::Api,
            $request->ip(),
        );

        return response()->json(['data' => $outcome->toArray()]);
    }
}

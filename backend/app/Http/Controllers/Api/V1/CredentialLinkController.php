<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlumniProfile;
use App\Models\CredentialLink;
use App\Services\Verification\CredentialLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** An alumnus's own verification links: create one to give an employer, see them, withdraw them. */
class CredentialLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return response()->json([
            'data' => $profile->credentialLinks()->active()->latest('id')->get()->map(fn (CredentialLink $link) => $this->present($link))->values(),
            // Lets the app say "not available yet" instead of offering a button that will fail.
            'meta' => [
                'can_create' => AlumniProfile::query()->verifiable()->whereKey($profile->id)->exists(),
                'valid_days' => (int) config('sunates.verification.link_valid_days'),
                'max_active' => CredentialLinkService::MAX_ACTIVE,
            ],
        ]);
    }

    public function store(Request $request, CredentialLinkService $links): JsonResponse
    {
        $link = $links->issue($this->profile($request));

        return response()->json(['data' => $this->present($link)], 201);
    }

    public function destroy(Request $request, CredentialLink $credentialLink, CredentialLinkService $links): JsonResponse
    {
        // Someone else's link looks the same as one that does not exist.
        abort_unless($credentialLink->alumni_profile_id === $this->profile($request)->id, 404);

        $links->revoke($credentialLink);

        return response()->json(null, 204);
    }

    private function profile(Request $request): AlumniProfile
    {
        return $request->user()->alumniProfile()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(CredentialLink $link): array
    {
        return [
            'id' => $link->id,
            'url' => $link->url(),
            'expires_at' => $link->expires_at->toIso8601String(),
            'views' => $link->views,
            'last_viewed_at' => $link->last_viewed_at?->toIso8601String(),
        ];
    }
}

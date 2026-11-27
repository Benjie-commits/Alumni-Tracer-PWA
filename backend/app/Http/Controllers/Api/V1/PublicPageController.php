<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The graduate's own switch for their public page: what is shown, and when it is withdrawn. */
class PublicPageController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state($this->profile($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $data = $request->validate([
            'published' => ['required', 'boolean'],
            'headline' => ['nullable', 'string', 'max:160'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', Rule::in(AlumniProfile::PUBLIC_SECTIONS)],
        ]);

        // Only a Registrar-verified graduate can publish: the page vouches for the graduation.
        abort_if($data['published'] && $profile->verification_status !== VerificationStatus::Verified, 422, 'Your graduation must be verified before you can publish a page.');

        $profile->forceFill([
            'public_headline' => filled($data['headline'] ?? null) ? trim(strip_tags($data['headline'])) : null,
            'public_sections' => array_values(array_unique($data['sections'] ?? [])),
            'public_slug' => $profile->public_slug ?: $this->newSlug($profile),
            'public_published_at' => $data['published'] ? ($profile->public_published_at ?? now()) : null,
        ])->save();

        return response()->json(['data' => $this->state($profile)]);
    }

    private function state(AlumniProfile $profile): array
    {
        return [
            'published' => $profile->public_published_at !== null,
            'headline' => $profile->public_headline,
            'sections' => $profile->public_sections ?? [],
            'url' => $profile->public_slug && $profile->public_published_at ? route('alumnus.show', $profile->public_slug) : null,
            'can_publish' => $profile->verification_status === VerificationStatus::Verified,
        ];
    }

    private function newSlug(AlumniProfile $profile): string
    {
        // Name plus a random tail, so the address can't be guessed from the name alone and never collides.
        do {
            $slug = Str::slug($profile->first_name.' '.$profile->last_name).'-'.Str::lower(Str::random(5));
        } while (AlumniProfile::withTrashed()->where('public_slug', $slug)->exists());

        return $slug;
    }

    private function profile(Request $request): AlumniProfile
    {
        return $request->user()->alumniProfile()->firstOrFail();
    }
}

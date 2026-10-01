<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurveyInvitationStatus;
use App\Http\Controllers\Controller;
use App\Models\SurveyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Surveys waiting for the signed-in alumnus, so they can be answered from the app as well as from a message. */
class MySurveysController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->alumniProfile()->firstOrFail();

        $open = SurveyInvitation::query()
            ->with('cycle')
            ->where('alumni_profile_id', $profile->id)
            ->whereIn('status', [SurveyInvitationStatus::Scheduled, SurveyInvitationStatus::Sent])
            ->where('expires_at', '>', now())
            ->orderBy('due_at')
            ->get();

        return response()->json(['data' => $open->map(fn (SurveyInvitation $invitation) => [
            'token' => $invitation->token,
            'title' => $invitation->cycle->title,
            'period' => $invitation->cycle->periodLabel(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ])->values()]);
    }
}

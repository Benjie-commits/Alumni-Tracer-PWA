<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurveyInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubmitSurveyRequest;
use App\Models\SurveyInvitation;
use App\Services\Surveys\SurveyAlreadyCompleted;
use App\Services\Surveys\SurveyClosed;
use App\Services\Surveys\SurveySubmissionService;
use Illuminate\Http\JsonResponse;

/**
 * The survey behind the link sent by SMS/WhatsApp. The unguessable token in the link is the
 * credential, so alumni on a phone with no saved sign-in can answer in two minutes. Nothing about
 * the person is revealed beyond their first name, and an unknown token looks exactly like a mistyped one.
 */
class SurveyController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);
        $cycle = $invitation->cycle;

        $data = [
            'status' => $this->statusOf($invitation),
            'title' => $cycle->title,
            'period' => $cycle->periodLabel(),
            'first_name' => $invitation->profile->first_name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];

        if ($data['status'] === 'open' && $version = $cycle->currentVersion) {
            $data += [
                'version' => $version->id,
                'intro' => $version->intro(),
                // maps_to is an internal detail of how answers feed the dashboards.
                'questions' => array_map(fn (array $q) => array_diff_key($q, ['maps_to' => true]), $version->questions()),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function store(SubmitSurveyRequest $request, string $token, SurveySubmissionService $submissions): JsonResponse
    {
        $invitation = $this->find($token);

        try {
            $result = $submissions->submit(
                $invitation,
                $request->validated('submission_id'),
                $request->validated('answers'),
                $request->validated('version'),
            );
        } catch (SurveyAlreadyCompleted) {
            return response()->json(['message' => 'This survey has already been completed. Thank you!'], 409);
        } catch (SurveyClosed) {
            return response()->json(['message' => 'This survey has closed.'], 410);
        }

        return response()->json(
            ['data' => ['status' => 'completed', 'already_saved' => ! $result->created]],
            $result->created ? 201 : 200,
        );
    }

    private function find(string $token): SurveyInvitation
    {
        $invitation = SurveyInvitation::query()
            ->with(['cycle.currentVersion', 'profile'])
            ->where('token', $token)
            ->first();

        abort_if($invitation === null, 404, 'This survey link is not valid.');

        return $invitation;
    }

    private function statusOf(SurveyInvitation $invitation): string
    {
        return match (true) {
            $invitation->status === SurveyInvitationStatus::Completed => 'completed',
            $invitation->isOpen() => 'open',
            default => 'expired',
        };
    }
}

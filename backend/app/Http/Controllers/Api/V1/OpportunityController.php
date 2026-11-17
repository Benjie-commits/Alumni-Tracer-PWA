<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlumniProfile;
use App\Models\EventPosting;
use App\Models\EventRsvp;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-5: the jobs, internships and events aimed at this alumnus's segment (school, department,
 * programme, graduation year). Published, still open / not yet over.
 */
class OpportunityController extends Controller
{
    public function jobs(Request $request): JsonResponse
    {
        $postings = JobPosting::query()
            ->published()->open()->tap(fn ($q) => $this->forViewer($q, $request))
            ->orderByRaw('closes_on IS NULL, closes_on')->latest('published_at')
            ->limit(100)->get();

        return response()->json(['data' => $postings->map(fn (JobPosting $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'organisation' => $p->organisation,
            'type' => ['value' => $p->type->value, 'label' => $p->type->label()],
            'location' => $p->location,
            'description' => $p->description,
            'how_to_apply' => $p->how_to_apply,
            'closes_on' => $p->closes_on?->toDateString(),
            'published_at' => $p->published_at->toIso8601String(),
        ])->values()]);
    }

    public function events(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $events = EventPosting::query()
            ->published()->upcoming()->tap(fn ($q) => $this->forViewer($q, $request))
            ->withCount(['rsvps as going_count' => fn ($q) => $q->where('response', 'going')])
            ->with(['rsvps' => fn ($q) => $q->where('user_id', $userId)])
            ->orderBy('starts_at')
            ->limit(100)->get();

        return response()->json(['data' => $events->map(fn (EventPosting $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'starts_at' => $e->starts_at->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'venue' => $e->venue,
            'online_url' => $e->online_url,
            'going_count' => $e->going_count,
            'my_rsvp' => $e->rsvps->first()?->response,
        ])->values()]);
    }

    /** Say whether you are coming. Only for an event that is published, not over, and aimed at you. */
    public function rsvp(Request $request, int $event): JsonResponse
    {
        $data = $request->validate(['response' => ['required', 'in:'.implode(',', EventRsvp::RESPONSES)]]);

        $posting = EventPosting::query()
            ->published()->upcoming()->tap(fn ($q) => $this->forViewer($q, $request))
            ->findOrFail($event);

        EventRsvp::query()->updateOrCreate(
            ['event_posting_id' => $posting->id, 'user_id' => $request->user()->id],
            ['response' => $data['response']],
        );

        return response()->json(['data' => [
            'my_rsvp' => $data['response'],
            'going_count' => $posting->rsvps()->where('response', 'going')->count(),
        ]]);
    }

    public function cancelRsvp(Request $request, int $event): JsonResponse
    {
        EventRsvp::query()->where('event_posting_id', $event)->where('user_id', $request->user()->id)->delete();

        return response()->json(['data' => ['my_rsvp' => null]]);
    }

    /** Alumni see what targets their programme and class; continuing students what targets their programme. */
    private function forViewer($query, Request $request): void
    {
        $user = $request->user();

        if ($user->isStudent()) {
            $query->visibleToStudent($user->student()->firstOrFail());

            return;
        }

        $query->visibleTo($user->alumniProfile()->firstOrFail());
    }
}

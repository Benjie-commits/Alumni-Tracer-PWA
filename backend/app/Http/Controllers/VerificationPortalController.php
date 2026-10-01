<?php

namespace App\Http\Controllers;

use App\Enums\EscalationStatus;
use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use App\Http\Requests\LookupRequest;
use App\Models\CredentialVerificationRequest;
use App\Models\Programme;
use App\Models\VerificationEscalation;
use App\Services\Verification\CredentialLinkService;
use App\Services\Verification\CredentialVerifier;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The lightweight web page employers use to check a graduate (spec section 5.4). No account, no
 * app: just a form. It is unauthenticated, so everything here is rate-limited and answers only
 * with graduation status, programme and year.
 */
class VerificationPortalController extends Controller
{
    public function show(): View
    {
        return $this->page();
    }

    public function lookup(LookupRequest $request, CredentialVerifier $verifier): View
    {
        $outcome = $verifier->lookup(
            $request->validated('name'),
            $request->validated('programme_id'),
            $request->validated('graduation_year'),
            $request->validated('organisation'),
            $request->validated('email'),
            VerificationChannel::Portal,
            $request->ip(),
        );

        return $this->page(['outcome' => $outcome]);
    }

    /**
     * "Ask the Registrar to check": when nothing was found, or two graduates could not be told apart,
     * the requester can leave their details and the Registrar's office follows up by hand.
     */
    public function enquiry(Request $request): View
    {
        // A hidden field only bots fill in. Pretend success so they learn nothing.
        if ($request->filled('website')) {
            return $this->page(['enquirySent' => true]);
        }

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:16'],
            'requester_name' => ['required', 'string', 'max:120'],
            'requester_email' => ['required', 'email:rfc', 'max:255'],
            'requester_phone' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $lookup = CredentialVerificationRequest::query()
            ->where('reference', $data['reference'])
            ->where('channel', '!=', VerificationChannel::Link)
            ->where('result', '!=', VerificationResult::Verified)
            ->first();

        abort_if($lookup === null, 404);

        // One enquiry per lookup: a repeat is told it was already received, never duplicated.
        VerificationEscalation::query()->firstOrCreate(
            ['request_reference' => $lookup->reference],
            [
                'organisation' => (string) $lookup->organisation,
                'requester_name' => $data['requester_name'],
                'requester_email' => $data['requester_email'],
                'requester_phone' => $data['requester_phone'] ?? null,
                'subject_name' => (string) $lookup->query_name,
                'subject_graduation_year' => $lookup->query_graduation_year,
                'subject_programme' => $lookup->query_programme_id ? Programme::query()->find($lookup->query_programme_id)?->name : null,
                'lookup_result' => $lookup->result,
                'message' => $data['message'] ?? null,
                'status' => EscalationStatus::Open,
            ],
        );

        return $this->page(['enquirySent' => true]);
    }

    /** A link an alumnus gave an employer. Unknown, expired and revoked links all look the same. */
    public function link(string $token, Request $request, CredentialLinkService $links): View
    {
        $outcome = $links->open($token, $request->ip());

        abort_if($outcome === null, 404);

        return view('verify-link', ['outcome' => $outcome]);
    }

    /** @param  array<string, mixed>  $extra */
    private function page(array $extra = []): View
    {
        return view('verify', $extra + [
            'programmes' => Programme::query()->with('department.school')->orderBy('name')->get()
                ->groupBy(fn (Programme $p) => $p->department->school->name.' › '.$p->department->name),
            'outcome' => null,
            'enquirySent' => false,
        ]);
    }
}

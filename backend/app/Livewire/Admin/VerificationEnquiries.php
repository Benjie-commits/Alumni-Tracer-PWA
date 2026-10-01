<?php

namespace App\Livewire\Admin;

use App\Enums\EscalationStatus;
use App\Enums\VerificationResult;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\CredentialVerificationRequest;
use App\Models\VerificationEscalation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Registrar's side of credential verification: enquiries from employers whose lookup found
 * nobody (or could not tell two graduates apart), and the log of every lookup. Both name outside
 * organisations and the people they asked about, so this is Registrar and ICT only.
 */
#[Layout('layouts::admin')]
#[Title('Verification enquiries')]
class VerificationEnquiries extends Component
{
    use AuthorizesStaff, WithPagination;

    #[Url]
    public string $tab = 'open';

    /** @var array<int, string> resolution notes typed per enquiry */
    public array $notes = [];

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'pagination.admin';
    }

    /** Record that the Registrar's office has dealt with an enquiry (they reply to the requester by email themselves). */
    public function resolve(int $escalationId): void
    {
        $this->authorizeManager();

        $this->validate(["notes.{$escalationId}" => ['nullable', 'string', 'max:1000']]);

        $escalation = VerificationEscalation::query()->where('status', EscalationStatus::Open)->findOrFail($escalationId);

        $escalation->update([
            'status' => EscalationStatus::Resolved,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
            'resolution_note' => ($this->notes[$escalationId] ?? '') !== '' ? $this->notes[$escalationId] : null,
        ]);

        unset($this->notes[$escalationId]);
    }

    public function render()
    {
        $this->authorizeManager();

        $tab = in_array($this->tab, ['open', 'resolved', 'log'], true) ? $this->tab : 'open';

        $since = now()->subDays(30);
        $byResult = CredentialVerificationRequest::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('result, count(*) as n')
            ->groupBy('result')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->result->value => (int) $row->n]);

        return view('livewire.admin.verification-enquiries', [
            'tab' => $tab,
            'openCount' => VerificationEscalation::query()->where('status', EscalationStatus::Open)->count(),
            'enquiries' => $tab === 'log' ? null : VerificationEscalation::query()
                ->with('resolver')
                ->where('status', $tab === 'open' ? EscalationStatus::Open : EscalationStatus::Resolved)
                ->orderBy($tab === 'open' ? 'created_at' : 'resolved_at', $tab === 'open' ? 'asc' : 'desc')
                ->paginate(15),
            'lookups' => $tab === 'log' ? CredentialVerificationRequest::query()->latest('id')->paginate(30) : null,
            'counts' => collect(VerificationResult::cases())->mapWithKeys(fn ($r) => [$r->value => $byResult[$r->value] ?? 0]),
        ]);
    }
}

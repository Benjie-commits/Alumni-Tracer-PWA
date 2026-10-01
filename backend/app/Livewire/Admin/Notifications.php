<?php

namespace App\Livewire\Admin;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\RoleSlug;
use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\NotificationLog;
use App\Services\Messaging\NotificationService;
use App\Support\PhoneNumber;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
#[Title('Messages')]
class Notifications extends Component
{
    use AuthorizesStaff, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $template = '';

    public string $testChannel = 'sms';

    public string $testNumber = '';

    /** Outcome of the last test message, shown under the form. */
    public ?string $testResult = null;

    public bool $testFailed = false;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'channel', 'template'], true)) {
            $this->resetPage();
        }
    }

    public function paginationView(): string
    {
        return 'pagination.admin';
    }

    /** ICT only: proves the SMS or WhatsApp credentials work before go-live. */
    public function sendTest(NotificationService $notifications): void
    {
        $this->authorizeIctAdmin();
        $this->testResult = null;

        $this->validate([
            'testChannel' => ['required', 'in:sms,whatsapp'],
            'testNumber' => ['required', 'string', 'max:32'],
        ]);

        $number = PhoneNumber::toE164($this->testNumber);
        if ($number === null) {
            $this->addError('testNumber', 'That does not look like a phone number. Try +256700000000.');

            return;
        }

        $log = $notifications->sendTest(NotificationChannel::from($this->testChannel), $number);

        $this->testFailed = $log->status === NotificationStatus::Failed;
        $this->testResult = $this->testFailed
            ? 'Not sent: '.$log->error
            : 'Accepted by the provider ('.($log->provider ?? 'unknown').'). Check the phone; delivery can take a minute.';
    }

    public function render()
    {
        $this->authorizeManager();

        $since = now()->subDays(30);

        $logs = NotificationLog::query()
            ->with('profile')
            ->when(NotificationStatus::tryFrom($this->status), fn ($q, $s) => $q->where('status', $s))
            ->when(NotificationChannel::tryFrom($this->channel), fn ($q, $c) => $q->where('channel', $c))
            ->when(MessageTemplate::tryFrom($this->template), fn ($q, $t) => $q->where('template', $t))
            ->latest('id')
            ->paginate(30);

        $byStatus = NotificationLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return view('livewire.admin.notifications', [
            'logs' => $logs,
            'counts' => collect(NotificationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'statuses' => NotificationStatus::cases(),
            'channels' => NotificationChannel::cases(),
            'templates' => MessageTemplate::cases(),
            'isIct' => auth()->user()->hasRole(RoleSlug::IctAdmin),
            'driversAreLive' => config('sunates.messaging.sms_driver') !== 'log' || config('sunates.messaging.whatsapp_driver') !== 'log',
        ]);
    }
}

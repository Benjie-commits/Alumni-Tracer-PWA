<?php

namespace App\Console\Commands;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\PermanentGatewayException;
use App\Services\Messaging\TransientGatewayException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sunates:sync-delivery-status')]
#[Description('Ask the SMS provider whether recently sent messages were delivered (WhatsApp reports by webhook instead)')]
class SyncDeliveryStatus extends Command
{
    public function handle(SmsGateway $sms): int
    {
        $logs = NotificationLog::query()
            ->where('channel', NotificationChannel::Sms)
            ->where('status', NotificationStatus::Sent)
            ->whereNotNull('provider_message_id')
            ->where('provider', '!=', 'log')
            // Give the network a couple of minutes, and stop asking after three days.
            ->whereBetween('created_at', [now()->subDays(3), now()->subMinutes(2)])
            ->orderBy('id')
            ->limit(200)
            ->get();

        $updated = 0;

        foreach ($logs as $log) {
            try {
                $status = $sms->deliveryStatus($log->provider_message_id);
            } catch (TransientGatewayException) {
                break; // the provider is struggling; try again on the next run
            } catch (PermanentGatewayException $e) {
                report($e);

                continue;
            }

            if ($status === null || $status === NotificationStatus::Sent) {
                continue;
            }

            $log->update([
                'status' => $status,
                'delivered_at' => in_array($status, [NotificationStatus::Delivered, NotificationStatus::Read], true) ? now() : null,
                'failed_at' => $status === NotificationStatus::Failed ? now() : null,
                'error' => $status === NotificationStatus::Failed ? 'Provider reported the message undeliverable.' : $log->error,
            ]);
            $updated++;
        }

        $this->info("Checked {$logs->count()} message(s), updated {$updated}.");

        return self::SUCCESS;
    }
}

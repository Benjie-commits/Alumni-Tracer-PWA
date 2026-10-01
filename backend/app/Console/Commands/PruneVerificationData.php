<?php

namespace App\Console\Commands;

use App\Enums\EscalationStatus;
use App\Models\CredentialLink;
use App\Models\CredentialVerificationRequest;
use App\Models\VerificationEscalation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sunates:prune-verification-data {--dry-run : Count what would be deleted without deleting it}')]
#[Description('Delete old verification lookups, resolved enquiries and dead credential links (retention policy)')]
class PruneVerificationData extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('sunates.verification.log_retention_days'));
        $dry = (bool) $this->option('dry-run');

        $queries = [
            'lookup log entries' => CredentialVerificationRequest::query()->where('created_at', '<', $cutoff),
            // An unresolved enquiry is still someone's work, however old, so only resolved ones are pruned.
            'resolved enquiries' => VerificationEscalation::query()->where('status', EscalationStatus::Resolved)->where('resolved_at', '<', $cutoff),
            // Links that expired or were withdrawn a month ago are of no use to anyone.
            'dead credential links' => CredentialLink::query()->where(fn ($q) => $q
                ->where('expires_at', '<', now()->subDays(30))
                ->orWhere('revoked_at', '<', now()->subDays(30))),
        ];

        foreach ($queries as $label => $query) {
            $count = $dry ? $query->count() : $query->delete();
            $this->line(($dry ? 'Would delete ' : 'Deleted ')."{$count} {$label}");
        }

        return self::SUCCESS;
    }
}

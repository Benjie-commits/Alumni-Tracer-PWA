<?php

namespace App\Enums;

enum ErpSyncStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to start',
            self::Running => 'Running',
            self::Succeeded => 'Finished',
            self::Failed => 'Failed',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}

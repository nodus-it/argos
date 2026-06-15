<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskProviderSyncStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('enums.task_provider_sync_status.pending'),
            self::Active => __('enums.task_provider_sync_status.active'),
            self::Error => __('enums.task_provider_sync_status.error'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Error => 'danger',
        };
    }
}

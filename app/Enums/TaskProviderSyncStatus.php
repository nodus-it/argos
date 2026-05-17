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
            self::Pending => 'Ausstehend',
            self::Active => 'Aktiv',
            self::Error => 'Fehler',
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

<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskProviderMode: string
{
    case Webhook = 'webhook';
    case Poll = 'poll';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Webhook => 'Webhook (Push)',
            self::Poll => 'Polling',
            self::Disabled => 'Deaktiviert',
        };
    }
}

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
            self::Webhook => __('enums.task_provider_mode.webhook'),
            self::Poll => __('enums.task_provider_mode.poll'),
            self::Disabled => __('enums.task_provider_mode.disabled'),
        };
    }
}

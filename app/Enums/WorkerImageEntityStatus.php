<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerImageEntityStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('enums.worker_image_entity_status.active'),
            self::Deprecated => __('enums.worker_image_entity_status.deprecated'),
            self::Disabled => __('enums.worker_image_entity_status.disabled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Deprecated => 'warning',
            self::Disabled => 'gray',
        };
    }
}

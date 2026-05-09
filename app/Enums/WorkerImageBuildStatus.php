<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerImageBuildStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('enums.worker_image_build_status.queued'),
            self::Building => __('enums.worker_image_build_status.building'),
            self::Ready => __('enums.worker_image_build_status.ready'),
            self::Failed => __('enums.worker_image_build_status.failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Building => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ready, self::Failed => true,
            default => false,
        };
    }
}

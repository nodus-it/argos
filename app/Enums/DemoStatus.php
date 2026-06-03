<?php

declare(strict_types=1);

namespace App\Enums;

enum DemoStatus: string
{
    case Building = 'building';
    case Live = 'live';
    case Failed = 'failed';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Building => __('enums.demo_status.building'),
            self::Live => __('enums.demo_status.live'),
            self::Failed => __('enums.demo_status.failed'),
            self::Stopped => __('enums.demo_status.stopped'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Building => 'warning',
            self::Live => 'success',
            self::Failed => 'danger',
            self::Stopped => 'gray',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum PhaseStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Paused = 'paused';
    case Failed = 'failed';
    case QualityGateFailed = 'quality_gate_failed';
    case NoChanges = 'no_changes';
    case LockBlocked = 'lock_blocked';
    case RateLimited = 'rate_limited';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('enums.phase_status.pending'),
            self::Running => __('enums.phase_status.running'),
            self::Completed => __('enums.phase_status.completed'),
            self::Paused => __('enums.phase_status.paused'),
            self::Failed => __('enums.phase_status.failed'),
            self::QualityGateFailed => __('enums.phase_status.quality_gate_failed'),
            self::NoChanges => __('enums.phase_status.no_changes'),
            self::LockBlocked => __('enums.phase_status.lock_blocked'),
            self::RateLimited => __('enums.phase_status.rate_limited'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'warning',
            self::Completed => 'success',
            self::Paused => 'warning',
            self::Failed => 'danger',
            self::QualityGateFailed => 'danger',
            self::NoChanges => 'info',
            self::LockBlocked => 'danger',
            self::RateLimited => 'warning',
        };
    }
}

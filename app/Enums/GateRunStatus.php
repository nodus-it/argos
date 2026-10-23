<?php

declare(strict_types=1);

namespace App\Enums;

/** Outcome of a workspace quality-gate run. */
enum GateRunStatus: string
{
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';

    public function label(): string
    {
        return __('enums.gate_run_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Running => 'warning',
            self::Passed => 'success',
            self::Failed, self::Error => 'danger',
        };
    }
}

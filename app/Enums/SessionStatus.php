<?php

declare(strict_types=1);

namespace App\Enums;

/** State of a task's daemon session, as reported by the workspace daemon. */
enum SessionStatus: string
{
    case Closed = 'closed';
    case Starting = 'starting';
    case Idle = 'idle';
    case Running = 'running';
    case WaitingInput = 'waiting_input';
    case Error = 'error';

    public function label(): string
    {
        return __('enums.session_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Closed => 'gray',
            self::Starting => 'info',
            self::Idle => 'success',
            self::Running => 'warning',
            self::WaitingInput => 'danger',
            self::Error => 'danger',
        };
    }

    /** A session is busy: the agent works or waits for a human. */
    public function isBusy(): bool
    {
        return $this === self::Running || $this === self::WaitingInput;
    }
}

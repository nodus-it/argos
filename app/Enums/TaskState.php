<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a person sees for a task: its status combined with what the session
 * does and whether it is archived. Drives the switcher groups and badges.
 */
enum TaskState: string
{
    case Preparing = 'preparing';
    case Running = 'running';
    case Waiting = 'waiting';
    case Ready = 'ready';
    case PrOpen = 'pr_open';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return __('enums.task_state.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Waiting => 'danger',
            self::Running, self::Preparing => 'warning',
            self::Ready => 'success',
            self::PrOpen => 'info',
            self::Done, self::Cancelled, self::Archived => 'gray',
        };
    }

    /**
     * Switcher group, in display order: waiting, running, ready, pr_open,
     * done (collapsed) and archived.
     */
    public function group(): string
    {
        return match ($this) {
            self::Waiting => 'waiting',
            self::Running, self::Preparing => 'running',
            self::Ready => 'ready',
            self::PrOpen => 'pr_open',
            self::Done, self::Cancelled => 'done',
            self::Archived => 'archived',
        };
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return ['waiting', 'running', 'ready', 'pr_open', 'done'];
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a task stands in its life: being prepared (worktree, database,
 * setup commands), worked on, waiting in a pull request, or finished. What
 * the session does right now is SessionStatus; TaskState combines both for
 * display.
 */
enum TaskStatus: string
{
    case Preparing = 'preparing';
    case Active = 'active';
    case PrOpen = 'pr_open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.task_status.'.$this->value);
    }

    /** Merged or closed: no more work happens in it. */
    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /**
     * Finished states (for queries).
     *
     * @return list<string>
     */
    public static function finishedValues(): array
    {
        return array_values(array_map(fn (self $status): string => $status->value, array_filter(self::cases(), fn (self $status): bool => $status->isFinished())));
    }
}

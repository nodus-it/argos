<?php

declare(strict_types=1);

namespace App\Enums;

/** Where an auto-task is in its run (see AutoTaskDriver). */
enum AutoTaskState: string
{
    case Queued = 'queued';
    case Planning = 'planning';
    case AwaitingPlanApproval = 'awaiting_plan_approval';
    case Implementing = 'implementing';
    case Gating = 'gating';
    case Fixing = 'fixing';
    case Shipping = 'shipping';
    case Done = 'done';
    case Paused = 'paused';
    case Failed = 'failed';
    case BudgetExceeded = 'budget_exceeded';

    public function label(): string
    {
        return __('enums.auto_task_state.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::AwaitingPlanApproval, self::Paused => 'warning',
            self::Failed, self::BudgetExceeded => 'danger',
            default => 'info',
        };
    }

    /** The driver no longer acts; only a human (resume) can continue. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Done, self::Failed, self::BudgetExceeded], true);
    }

    /** The driver is in charge (not paused, not finished). */
    public function isRunning(): bool
    {
        return ! $this->isFinal() && $this !== self::Paused;
    }

    /**
     * States in which no automation drives the task (for queries).
     *
     * @return list<string>
     */
    public static function idleValues(): array
    {
        return array_values(array_map(fn (self $state): string => $state->value, array_filter(self::cases(), fn (self $state): bool => ! $state->isRunning())));
    }
}

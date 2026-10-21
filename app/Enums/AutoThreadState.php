<?php

declare(strict_types=1);

namespace App\Enums;

/** Where an auto-thread is in its run (see AutoThreadDriver). */
enum AutoThreadState: string
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
        return __('enums.auto_thread_state.'.$this->value);
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
}

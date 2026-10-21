<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether an auto-task plans first, and who accepts the plan. */
enum AutoPlanMode: string
{
    /** Implement right away. */
    case Off = 'off';
    /** Plan, then continue on its own. */
    case Auto = 'auto';
    /** Plan, then wait for a human to approve. */
    case Approve = 'approve';

    public function label(): string
    {
        return __('enums.auto_plan_mode.'.$this->value);
    }
}

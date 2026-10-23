<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** Whether an auto-task plans first, and who accepts the plan. */
enum AutoPlanMode: string
{
    use ProvidesOptions;

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

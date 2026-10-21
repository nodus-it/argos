<?php

declare(strict_types=1);

namespace App\Enums;

/** What an auto-task does when the work is done. */
enum AutoShipMode: string
{
    case PullRequest = 'pr';
    case DraftPullRequest = 'draft_pr';
    /** Commit and push only. */
    case None = 'none';

    public function label(): string
    {
        return __('enums.auto_ship_mode.'.$this->value);
    }
}

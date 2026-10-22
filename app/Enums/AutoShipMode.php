<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;
/** What an auto-task does when the work is done. */
enum AutoShipMode: string
{
    use ProvidesOptions;

    case PullRequest = 'pr';
    case DraftPullRequest = 'draft_pr';
    /** Commit and push only. */
    case None = 'none';

    public function label(): string
    {
        return __('enums.auto_ship_mode.'.$this->value);
    }
}

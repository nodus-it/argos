<?php

declare(strict_types=1);

namespace App\Listeners\Task;

use App\Events\Task\PhaseCompleted;
use App\Services\IssueTracker\IssueCommentNotifier;

/**
 * Notify the external issue tracker (if this task was imported from one).
 * Errors are swallowed inside the notifier — the workflow must never stall.
 */
final class NotifyIssueTrackerOfPhase
{
    public function __construct(private readonly IssueCommentNotifier $notifier) {}

    public function handle(PhaseCompleted $event): void
    {
        $this->notifier->notifyPhaseCompletion($event->task, $event->phase->value, $event->status->value);
    }
}

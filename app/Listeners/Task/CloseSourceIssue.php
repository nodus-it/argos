<?php

declare(strict_types=1);

namespace App\Listeners\Task;

use App\Events\Task\TaskCompleted;
use App\Services\IssueTracker\IssueStatusSync;

/**
 * When a task is marked completed, close/resolve the source issue it was
 * imported from. Opt-in per binding and best-effort — handled inside the sync.
 */
final class CloseSourceIssue
{
    public function __construct(private readonly IssueStatusSync $statusSync) {}

    public function handle(TaskCompleted $event): void
    {
        $this->statusSync->closeSourceIssue($event->task);
    }
}

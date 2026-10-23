<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Task;
use App\Services\Workspace\PullRequestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Background refresh of a task's pull request details (stale cache, first load). */
class RefreshPullRequestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $taskId) {}

    public function handle(PullRequestService $pullRequests): void
    {
        $task = Task::query()->find($this->taskId);
        if ($task !== null) {
            $pullRequests->refresh($task);
        }
    }
}

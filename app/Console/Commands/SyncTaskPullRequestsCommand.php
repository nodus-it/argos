<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\GitProvider\PullRequestDetails;
use App\Services\Workspace\PullRequestService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refreshes the state of tasks' open pull requests; a merged one finishes
 * its task, a closed one cancels it.
 */
class SyncTaskPullRequestsCommand extends Command
{
    protected $signature = 'argos:tasks:sync-prs';

    protected $description = 'Refresh the pull request state of tasks with an open pull request.';

    public function handle(PullRequestService $pullRequests): int
    {
        Task::query()
            ->with('workspace')
            ->whereHas('workspace')
            ->whereNotNull('pr_number')
            ->whereIn('pr_state', PullRequestDetails::OPEN_STATES)
            ->each(function (Task $task) use ($pullRequests): void {
                // One broken task must not keep the others from syncing.
                try {
                    $pullRequests->refresh($task);
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Console\Command;

/**
 * Refreshes the state of tasks' open pull requests; a merged one finishes
 * its task, a closed one cancels it.
 */
class SyncTaskPullRequestsCommand extends Command
{
    protected $signature = 'argos:tasks:sync-prs';

    protected $description = 'Refresh the pull request state of tasks with an open pull request.';

    public function handle(WorkspaceShipService $ship): int
    {
        Task::query()
            ->with('workspace')
            ->whereNotNull('pr_number')
            ->whereIn('pr_state', ['open', 'draft'])
            ->each(fn (Task $task) => $ship->syncPullRequest($task));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\Workspace\TaskCleanup;
use Illuminate\Console\Command;

/**
 * Cleans up finished tasks (done or cancelled) once their workspace's
 * `cleanup_after_days` have passed: preview, worktree and database go, the
 * conversation stays readable. Remote branches and pull requests are kept.
 */
class CleanupFinishedTasksCommand extends Command
{
    protected $signature = 'argos:tasks:cleanup {--dry-run : List what would be cleaned up}';

    protected $description = 'Remove preview, worktree and database of tasks that finished long enough ago.';

    public function handle(TaskCleanup $cleanup): int
    {
        $cleaned = 0;

        Task::query()
            ->with('workspace')
            ->whereNotNull('done_at')
            ->whereNull('cleaned_at')
            ->where('session_open', false)
            ->each(function (Task $task) use ($cleanup, &$cleaned): void {
                if ($task->done_at === null || $task->done_at->gt(now()->subDays($task->workspace->cleanup_after_days))) {
                    return;
                }
                $this->line(($this->option('dry-run') ? '[dry-run] ' : '').'Cleaning up '.$task->workspace->slug.'/'.$task->slug);
                if (! $this->option('dry-run')) {
                    $cleanup->cleanup($task);
                }
                $cleaned++;
            });

        $this->info("{$cleaned} finished task(s) cleaned up.");

        return self::SUCCESS;
    }
}

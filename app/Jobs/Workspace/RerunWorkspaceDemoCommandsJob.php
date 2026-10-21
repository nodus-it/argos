<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Task;
use App\Services\Demo\DemoDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Re-runs the demo contract's setup commands in a task's live preview. */
class RerunWorkspaceDemoCommandsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly string $taskId) {}

    public function handle(DemoDeployer $deployer): void
    {
        $task = Task::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $deployer->rerunCommands($task);
    }
}

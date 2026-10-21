<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Task;
use App\Services\Workspace\WorkspacePreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Stops a task's live preview (TTL expiry). */
class StopWorkspaceDemoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly string $taskId) {}

    public function handle(WorkspacePreviewService $previews): void
    {
        $task = Task::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $previews->stop($task);
    }
}

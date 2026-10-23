<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Enums\WorkspaceSetupStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\WorkspaceSetupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** "Test setup": ships and checks a setup task's branch (WorkspaceSetupService::check). */
class CheckWorkspaceSetupJob implements ShouldQueue
{
    use Queueable;

    /** Image build, setup steps and preview can each take a while. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly string $taskId, public readonly string $userId)
    {
        $this->onQueue('tasks');
    }

    public function handle(WorkspaceSetupService $setup): void
    {
        $task = Task::query()->find($this->taskId);
        $user = User::query()->find($this->userId);
        if ($task === null || $user === null) {
            return;
        }

        $setup->check($task, $user);
    }

    public function failed(?Throwable $e): void
    {
        Task::query()->find($this->taskId)?->workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Failed])->save();
    }
}

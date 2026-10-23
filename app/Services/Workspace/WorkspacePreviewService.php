<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\DemoStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\DeployWorkspaceDemoJob;
use App\Jobs\Workspace\RerunWorkspaceDemoCommandsJob;
use App\Models\Demo;
use App\Models\Task;
use App\Models\User;
use App\Services\Demo\DemoDeployer;
use Illuminate\Support\Facades\Gate;

/**
 * The live preview of a task: the regular demo system pointed at the task's
 * files, members-only, started on demand. A workspace runs at most
 * `max_previews` at once; the oldest one makes room.
 */
class WorkspacePreviewService
{
    public function __construct(private readonly DemoDeployer $deployer) {}

    /** Why previews are unavailable for this task, or null when they are. */
    public function unavailableReason(Task $task): ?string
    {
        $workspace = $task->workspace;

        return match (true) {
            ! (bool) config('argos.preview.enabled') => __('workspaces.preview.disabled_platform'),
            ! (bool) $workspace->live_demo_enabled => __('workspaces.preview.disabled_project'),
            $workspace->status !== WorkspaceStatus::Running => __('workspaces.tabs.not_running'),
            default => null,
        };
    }

    /** Build or rebuild the preview (replaces a running one). */
    public function start(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('prompt', $task);
        $reason = $this->unavailableReason($task);
        if ($reason !== null) {
            throw new WorkspaceException($reason);
        }

        DeployWorkspaceDemoJob::dispatch($task->id);
    }

    public function rerunCommands(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('prompt', $task);
        if ($task->currentDemo()?->status !== DemoStatus::Live) {
            throw WorkspaceException::translated('workspaces.preview.not_live');
        }

        RerunWorkspaceDemoCommandsJob::dispatch($task->id);
    }

    public function stop(Task $task, ?User $by = null): void
    {
        if ($by !== null) {
            Gate::forUser($by)->authorize('prompt', $task);
        }

        $this->deployer->teardown($task);
        Demo::query()
            ->where('task_id', $task->id)
            ->whereIn('status', [DemoStatus::Building->value, DemoStatus::Live->value])
            ->update(['status' => DemoStatus::Stopped->value, 'url' => null]);
    }

    /** Stop every preview of the workspace (container shutdown). */
    public function stopAll(string $workspaceId): void
    {
        Task::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('demos', fn ($q) => $q->whereIn('status', [DemoStatus::Building->value, DemoStatus::Live->value]))
            ->get()
            ->each(fn (Task $task) => $this->stop($task));
    }
}

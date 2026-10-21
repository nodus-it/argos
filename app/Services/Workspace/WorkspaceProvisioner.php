<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\WorkspaceStatus;
use App\Enums\TaskStatus;
use App\Events\Workspace\WorkspaceStarted;
use App\Events\Workspace\WorkspaceStopped;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\Demo\TraefikRouter;
use App\Services\Worker\WorkerSidecarManager;
use App\Workers\Compose\WorkerImageResolver;
use App\Workers\Compose\WorkspaceImageBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings a workspace's container up (first provisioning and every restart)
 * and down. Runs inside queued jobs; failures land on the workspace as
 * `failed` with a readable status message.
 */
class WorkspaceProvisioner
{
    public function __construct(
        private readonly WorkerImageResolver $images,
        private readonly WorkspaceImageBuilder $imageBuilder,
        private readonly WorkspaceContainerManager $containers,
        private readonly WorkerSidecarManager $sidecars,
        private readonly WorkspaceDaemonClient $daemon,
        private readonly TaskService $tasks,
        private readonly TraefikRouter $router,
    ) {}

    /**
     * Image → volumes → sidecars → container → daemon health → main clone.
     * Idempotent: a restart re-runs every step; the clone is skipped when
     * the volume already holds one. Tasks still preparing (waiting for the
     * container) carry on afterwards.
     */
    public function boot(Workspace $workspace): void
    {
        try {
            $resolved = $this->images->resolveForWorkspace($workspace, $workspace->setupRef());
            $image = $this->imageBuilder->ensure($resolved)['tag'];

            $this->containers->prepareVolumes($workspace);
            $sidecars = $this->sidecars->startForWorkspace($workspace);
            $this->containers->run($workspace, $image, $sidecars);
            $this->containers->waitForHealth($workspace);
            $this->router->writeCodeRoute($workspace->slug, (int) config('argos.workspaces.editor_port', 3000));

            $creator = $workspace->creator;
            $this->daemon->for($workspace, 600)->initWorkspace([
                'repo_url' => $workspace->repo_url,
                // The main clone; tasks branch off it in their own worktrees.
                'base_branch' => $workspace->default_branch,
                'git_user_name' => $creator !== null ? $creator->name : 'Argos',
                'git_user_email' => $creator !== null ? $creator->email : 'argos@localhost',
            ]);
        } catch (Throwable $e) {
            $this->fail($workspace, $e, 'Workspace boot failed');

            throw $e;
        }

        $workspace->forceFill([
            'status' => WorkspaceStatus::Running,
            'status_message' => null,
            'container_name' => $workspace->containerName(),
            'last_activity_at' => now(),
        ])->save();

        event(new WorkspaceStarted($workspace));

        Task::query()->where('workspace_id', $workspace->id)->where('status', TaskStatus::Preparing->value)->pluck('id')
            ->each(fn (string $id) => PrepareTaskJob::dispatch($id));
    }

    /**
     * Close open sessions, then stop the container and its sidecars. A
     * failure marks the workspace `failed` (never stuck in `stopping`).
     */
    public function shutdown(Workspace $workspace): void
    {
        try {
            Task::query()->where('workspace_id', $workspace->id)->where('session_open', true)->get()
                ->each(fn (Task $task) => $this->tasks->close($task));

            app(WorkspacePreviewService::class)->stopAll($workspace->id);
            $this->router->removeCodeRoute($workspace->slug);
            $this->containers->stop($workspace);
            $this->sidecars->stopWorkspaceContainers($workspace);
        } catch (Throwable $e) {
            $this->fail($workspace, $e, 'Workspace shutdown failed');

            throw $e;
        }

        $workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();
        event(new WorkspaceStopped($workspace));
    }

    private function fail(Workspace $workspace, Throwable $e, string $logMessage): void
    {
        Log::channel('argos')->warning($logMessage, ['workspace' => $workspace->slug, 'error' => $e->getMessage()]);

        $message = $e instanceof DaemonTransportException && $e->errorCode !== null
            ? __('workspaces.errors.init_failed', ['code' => $e->errorCode, 'message' => $e->getMessage()])
            : $e->getMessage();

        $workspace->forceFill([
            'status' => WorkspaceStatus::Failed,
            'status_message' => mb_substr($message, 0, 4000),
        ])->save();
    }
}

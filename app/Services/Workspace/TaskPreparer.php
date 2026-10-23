<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\AutoTaskState;
use App\Enums\BackingService;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Models\Task;
use App\Models\User;
use App\Services\Worker\WorkerSidecarManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Gets a new task ready to work in (status `preparing` → `active`): its own
 * database on the workspace's MySQL sidecar, then the daemon creates the
 * worktree on the task's branch, writes `.env` and runs the setup commands.
 * The daemon reports back with prepare.* events (see onEvent()).
 */
class TaskPreparer
{
    /** Retry delay while the workspace container comes up. */
    public const RETRY_SECONDS = 15;

    public function __construct(
        private readonly WorkspaceDaemonClient $daemon,
        private readonly WorkerSidecarManager $sidecars,
        private readonly TaskEnvironment $environment,
        private readonly WorkspaceService $workspaces,
    ) {}

    /**
     * Start the preparation. Returns false while the workspace is not up yet
     * (it is started; try again later).
     */
    public function prepare(Task $task): bool
    {
        if ($task->status !== TaskStatus::Preparing) {
            return true;
        }

        $workspace = $task->workspace;
        if ($workspace->status !== WorkspaceStatus::Running) {
            if (in_array($workspace->status, [WorkspaceStatus::Stopped, WorkspaceStatus::Failed], true)) {
                $this->workspaces->start($workspace);
            }

            return false;
        }

        if (in_array(BackingService::Mysql, $workspace->backingServices(), true)) {
            $database = TaskEnvironment::databaseName($task);
            $this->sidecars->createTaskDatabase($workspace, $database);
            $task->forceFill(['db_name' => $database])->save();
        }

        try {
            $this->daemon->for($workspace, 30)->prepareWorktree($task->slug, [
                'task_id' => $task->id,
                'branch' => (string) $task->branch,
                'base_branch' => $task->base_branch,
                'env' => $this->environment->values($task),
                'seq_offset' => $task->last_seq,
                // An unmerged workspace setup: its setup steps apply already.
                ...($workspace->setup_branch !== null && $workspace->setup_branch !== $task->branch ? ['setup_ref' => $workspace->setup_branch] : []),
            ]);
        } catch (DaemonTransportException $e) {
            $this->failed($task, $e->errorCode ?? 'daemon', $e->getMessage());
        }

        return true;
    }

    /**
     * Apply a prepare.* event (from TaskEventIngestor, inside its
     * transaction). Returns the notice to show in the chat, if any.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null payload of the notice
     */
    public function onEvent(Task $task, string $type, array $data): ?array
    {
        return match ($type) {
            'prepare.step' => ['kind' => 'prepare_step', 'step' => (string) ($data['step'] ?? '')],
            'prepare.completed' => $this->completed($task, $data),
            'prepare.failed' => ['kind' => 'prepare_failed', 'message' => mb_substr((string) ($data['message'] ?? ''), 0, 2000)],
            default => null,
        };
    }

    /**
     * Try again after a failed preparation (the task is still `preparing`).
     */
    public function retry(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('prompt', $task);
        if ($task->status !== TaskStatus::Preparing) {
            return;
        }

        PrepareTaskJob::dispatch($task->id);
    }

    /** After the ingest committed: let a waiting auto-task go on. */
    public function afterPrepared(Task $task): void
    {
        if ($task->status === TaskStatus::Active && $task->kind === TaskKind::Setup) {
            app(WorkspaceSetupService::class)->begin($task);
        }
        if ($task->status === TaskStatus::Active && $task->auto_state === AutoTaskState::Queued) {
            AdvanceAutoTaskJob::dispatch($task->id, 'begin');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function completed(Task $task, array $data): array
    {
        $baseCommit = (string) ($data['base_commit'] ?? '');
        $task->forceFill([
            'worktree_path' => is_string($data['path'] ?? null) ? $data['path'] : $task->worktree_path,
            'base_commit' => preg_match('/^[0-9a-f]{40}$/', $baseCommit) === 1 ? $baseCommit : $task->base_commit,
            'cleaned_at' => null,
        ]);
        if ($task->status === TaskStatus::Preparing) {
            $task->status = TaskStatus::Active;
        }

        return [
            'kind' => 'prepared',
            'setup' => in_array($data['setup'] ?? null, ['ok', 'failed', 'skipped'], true) ? $data['setup'] : 'skipped',
            'log' => mb_substr((string) ($data['log'] ?? ''), -8000),
        ];
    }

    private function failed(Task $task, string $code, string $message): void
    {
        Log::channel('argos')->warning('Task preparation failed', ['task' => $task->id, 'code' => $code, 'error' => $message]);
        app(TaskNoticeService::class)->systemNotice($task, 'prepare_failed', ['message' => mb_substr($message, 0, 2000)]);
    }
}

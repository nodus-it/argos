<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Task;
use App\Services\Worker\WorkerSidecarManager;
use Illuminate\Support\Facades\Log;

/**
 * Frees what a finished task holds in its workspace: the preview, the
 * worktree with its local branch, and its database. The conversation, the
 * remote branch and the pull request stay; work no remote holds is kept
 * under a backup ref in the main clone. Needs the workspace container
 * running; a stopped workspace is cleaned up on a later pass.
 */
class TaskCleanup
{
    public function __construct(
        private readonly WorkspacePreviewService $previews,
        private readonly WorkspaceDaemonClient $daemon,
        private readonly WorkerSidecarManager $sidecars,
    ) {}

    /** False when the workspace is not running (nothing was removed). */
    public function cleanup(Task $task): bool
    {
        $workspace = $task->workspace;
        if ($workspace->status !== WorkspaceStatus::Running) {
            return false;
        }

        $this->previews->stop($task);
        try {
            $backup = $this->daemon->for($workspace, 60)->removeWorktree($task->slug);
        } catch (DaemonTransportException $e) {
            Log::channel('argos')->info('Task worktree removal failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return false;
        }
        if ($task->db_name !== null) {
            $this->sidecars->dropTaskDatabase($workspace, $task->db_name);
        }
        $task->forceFill(['cleaned_at' => now()])->save();
        if ($backup !== null) {
            // Work no remote held is kept in the main clone — say where.
            Log::channel('argos')->info('Unpushed task work kept under a backup ref', ['task' => $task->id, 'ref' => $backup]);
            app(TaskNoticeService::class)->systemNotice($task, 'work_backed_up', ['ref' => $backup]);
        }

        return true;
    }
}

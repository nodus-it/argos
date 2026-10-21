<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\Task;

/**
 * Frees what a finished task holds in its workspace — the preview (and,
 * with per-task working copies, its worktree and database). The
 * conversation, the remote branch and the pull request stay.
 */
class TaskCleanup
{
    public function __construct(private readonly WorkspacePreviewService $previews) {}

    public function cleanup(Task $task): void
    {
        $this->previews->stop($task);
        $task->forceFill(['cleaned_at' => now()])->save();
    }
}

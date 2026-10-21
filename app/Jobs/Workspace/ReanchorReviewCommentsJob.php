<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Task;
use App\Services\Workspace\ReviewCommentService;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After Claude finished a turn: let collected review comments follow their
 * lines, or mark them stale when Claude changed those lines.
 */
class ReanchorReviewCommentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $taskId) {}

    public function handle(ReviewCommentService $comments, WorkspaceDaemonClient $daemon): void
    {
        $task = Task::query()->with('workspace')->find($this->taskId);
        if ($task === null || $task->workspace->status !== WorkspaceStatus::Running) {
            return;
        }

        $api = $daemon->for($task->workspace, 15);
        $contents = [];
        foreach ($comments->anchoredPaths($task) as $path) {
            try {
                $contents[$path] = $api->fileContent($task->slug, $path)['content'];
            } catch (DaemonTransportException $e) {
                if ($e->errorCode === 'not_found') {
                    // The file is gone: its comments can no longer match.
                    $contents[$path] = '';
                }
            }
        }

        $comments->reanchor($task, $contents);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Thread;
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

    public function __construct(public readonly string $threadId) {}

    public function handle(ReviewCommentService $comments, WorkspaceDaemonClient $daemon): void
    {
        $thread = Thread::query()->with('workspace')->find($this->threadId);
        if ($thread === null || $thread->workspace->status !== WorkspaceStatus::Running) {
            return;
        }

        $api = $daemon->for($thread->workspace, 15);
        $contents = [];
        foreach ($comments->anchoredPaths($thread) as $path) {
            try {
                $contents[$path] = $api->fileContent($path)['content'];
            } catch (DaemonTransportException $e) {
                if ($e->errorCode === 'not_found') {
                    // The file is gone: its comments can no longer match.
                    $contents[$path] = '';
                }
            }
        }

        $comments->reanchor($thread, $contents);
    }
}

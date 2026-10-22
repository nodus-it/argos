<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** `git fetch` in the workspace's main clone, off the request path. */
class FetchWorkspaceRemoteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $workspaceId) {}

    public function handle(WorkspaceDaemonClient $daemon): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if ($workspace === null) {
            return;
        }

        try {
            $daemon->for($workspace, 90)->gitFetch();
        } catch (Throwable) {
            // Offline remote: the diff uses what the clone has.
        }
    }
}

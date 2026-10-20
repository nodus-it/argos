<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Workspace;
use App\Services\Workspace\WorkspacePreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Stops a workspace's live preview (TTL expiry, workspace stop). */
class StopWorkspaceDemoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly string $workspaceId) {}

    public function handle(WorkspacePreviewService $previews): void
    {
        $workspace = Workspace::query()->withTrashed()->find($this->workspaceId);
        if ($workspace === null) {
            return;
        }

        $previews->stop($workspace);
    }
}

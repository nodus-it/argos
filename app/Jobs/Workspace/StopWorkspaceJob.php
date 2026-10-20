<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Stops a workspace container and its sidecars; volumes stay. */
class StopWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly string $workspaceId)
    {
        $this->onQueue('tasks');
    }

    public function handle(WorkspaceProvisioner $provisioner): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if ($workspace === null) {
            return;
        }

        $provisioner->shutdown($workspace);
    }
}

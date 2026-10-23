<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings a workspace up: first provisioning and every (re)start. A first run
 * may build the worker and workspace images, hence the long timeout.
 */
class ProvisionWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 2400;

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

        $provisioner->boot($workspace);
    }
}

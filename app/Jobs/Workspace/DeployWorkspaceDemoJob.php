<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Workspace;
use App\Services\Demo\DemoDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Builds (or rebuilds) the live preview of a workspace. */
class DeployWorkspaceDemoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly string $workspaceId) {}

    public function handle(DemoDeployer $deployer): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if ($workspace === null) {
            return;
        }

        $deployer->deploy($workspace);
    }
}

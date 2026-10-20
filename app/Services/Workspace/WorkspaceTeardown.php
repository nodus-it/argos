<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Services\Demo\DemoDeployer;
use App\Services\Demo\TraefikRouter;
use App\Services\Workflow\WorkerSidecarManager;

/**
 * Removes every Docker resource of a workspace by slug alone, so it also
 * works for orphans whose row is already gone: container, sidecars (with
 * their data), the live demo and both volumes.
 */
class WorkspaceTeardown
{
    public function __construct(
        private readonly WorkspaceContainerManager $containers,
        private readonly WorkerSidecarManager $sidecars,
        private readonly DemoDeployer $demos,
        private readonly TraefikRouter $router,
    ) {}

    public function purge(string $slug): void
    {
        $this->router->removeCodeRoute($slug);
        $this->containers->removeBySlug($slug);
        $this->sidecars->purgeWorkspace($slug);
        $this->demos->teardownBySlug('demo-ws-'.$slug);
        $this->containers->removeVolumesBySlug($slug);
    }
}

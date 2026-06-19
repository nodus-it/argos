<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\Services\Demo\DemoDeployer;
use App\Services\Workflow\RunResourceReaper;
use Illuminate\Support\Facades\Process;

/**
 * The single place that drops a task's Docker-side resources: its workspace
 * volume, any live demo stack (+ Traefik route), and any per-run worker/sidecar
 * containers and run network. Everything is addressed by identifier (volume
 * name, demo slug, task id) rather than a Task row, so it still works after the
 * task has been deleted and its rows cascaded away.
 *
 * Manager-side only (needs the docker socket). Used by TeardownTaskJob (on task
 * delete) and by the task-completed volume listener; never on a web request.
 */
class TaskTeardown
{
    public function __construct(
        private readonly RunResourceReaper $reaper,
        private readonly DemoDeployer $demoDeployer,
    ) {}

    /**
     * Full teardown for a gone task: run containers/network, demo stack, volume.
     */
    public function purge(string $taskId, string $volumeName, string $demoSlug): void
    {
        $this->reaper->reapTask($taskId);
        $this->demoDeployer->teardownBySlug($demoSlug);
        $this->removeVolume($volumeName);
    }

    /**
     * Remove just the workspace volume — the completed-task path, where the demo
     * keeps running for preview and there is no live phase run to reap.
     */
    public function removeVolume(string $volumeName): void
    {
        Process::run(['docker', 'volume', 'rm', $volumeName]);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Services\Demo\DemoDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deploys (or replaces) the live demo for a task after a successful implement
 * run. Dispatched by PhaseRunner only when the repo profile has
 * `live_demo_enabled` and `preview.enabled` is on.
 *
 * Like phase jobs, a demo deploy is expensive (compose up + in-container
 * commands), so it does not blindly retry — DemoDeployer records a failed Demo
 * row with the build log instead, which the UI surfaces.
 */
class DeployDemoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly string $taskId) {}

    public function handle(DemoDeployer $deployer): void
    {
        $task = Task::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $deployer->deploy($task);
    }
}

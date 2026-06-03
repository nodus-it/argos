<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DemoStatus;
use App\Models\Task;
use App\Services\Demo\DemoDeployer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tears down a task's running live demo (containers + volumes + Traefik route)
 * and marks the demo row stopped. Runs in the background because it shells out
 * to `docker compose down` — never on page load.
 */
class StopDemoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly string $taskId) {}

    public function handle(DemoDeployer $deployer): void
    {
        $task = Task::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $deployer->teardown($task);

        $demo = $task->currentDemo();
        $demo?->update(['status' => DemoStatus::Stopped, 'url' => null]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\DemoStatus;
use App\Models\Demo;
use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * Attaches one live-demo deployment per DemoStatus to four given tasks, so the
 * demo panel can be eyeballed in each state (building, live, failed, stopped).
 * Idempotent on the demo's compose_project.
 */
final class DemoDeploymentBuilder
{
    public function attachAllStatuses(Task $building, Task $live, Task $failed, Task $stopped): void
    {
        $this->demo($building, DemoStatus::Building, 'demo-building', null, null, now()->addDay(), null);
        $this->demo($live, DemoStatus::Live, 'demo-live', 'https://demo-live.127.0.0.1.nip.io:8080', 8080, now()->addDay(), "Cloning repo…\nBuilding image…\nDemo build succeeded.");
        $this->demo($failed, DemoStatus::Failed, 'demo-failed', null, null, now()->addDay(), "Cloning repo…\nBuilding image…\nimage build failed: exit code 1");
        $this->demo($stopped, DemoStatus::Stopped, 'demo-stopped', null, null, now()->subHour(), null);
    }

    private function demo(
        Task $task,
        DemoStatus $status,
        string $composeProject,
        ?string $url,
        ?int $port,
        Carbon $ttlUntil,
        ?string $buildLog,
    ): Demo {
        return Demo::updateOrCreate(
            ['compose_project' => $composeProject],
            [
                'task_id' => $task->id,
                'status' => $status->value,
                'url' => $url,
                'port' => $port,
                'ttl_until' => $ttlUntil,
                'build_log' => $buildLog,
            ],
        );
    }
}

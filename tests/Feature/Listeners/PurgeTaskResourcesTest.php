<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Jobs\TeardownTaskJob;
use App\Models\Task;
use App\Services\Demo\DemoDeployer;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PurgeTaskResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_task_queues_a_full_docker_teardown(): void
    {
        Bus::fake();
        Process::fake();

        $task = Task::factory()->create(['name' => 'My Task']);
        $expectedSlug = app(DemoDeployer::class)->demoSlug($task);
        $expectedVolume = $task->volumeName();

        app(TaskService::class)->deleteTask($task);

        Bus::assertDispatched(TeardownTaskJob::class, fn (TeardownTaskJob $job): bool => $job->taskId === $task->id
            && $job->volumeName === $expectedVolume
            && $job->demoSlug === $expectedSlug);
    }
}

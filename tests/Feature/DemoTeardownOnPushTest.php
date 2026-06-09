<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PhaseStatus;
use App\Jobs\StopDemoJob;
use App\Models\Demo;
use App\Models\Task;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * M6: the live demo is a pre-PR preview — once the push phase creates the PR,
 * it is torn down automatically (and stays restartable from the UI).
 */
final class DemoTeardownOnPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_completing_push_tears_down_a_live_demo(): void
    {
        $task = Task::factory()->create();
        Demo::factory()->live()->create(['task_id' => $task->id]);

        app(TaskService::class)->completePhase($task, 'push', PhaseStatus::Completed);

        Bus::assertDispatched(StopDemoJob::class, fn (StopDemoJob $j): bool => $j->taskId === $task->id);
    }

    public function test_completing_push_without_a_demo_dispatches_nothing(): void
    {
        $task = Task::factory()->create();

        app(TaskService::class)->completePhase($task, 'push', PhaseStatus::Completed);

        Bus::assertNotDispatched(StopDemoJob::class);
    }

    public function test_failed_push_does_not_tear_down_the_demo(): void
    {
        $task = Task::factory()->create();
        Demo::factory()->live()->create(['task_id' => $task->id]);

        app(TaskService::class)->completePhase($task, 'push', PhaseStatus::Failed);

        Bus::assertNotDispatched(StopDemoJob::class);
    }
}

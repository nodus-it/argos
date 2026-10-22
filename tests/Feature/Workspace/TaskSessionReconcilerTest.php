<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Integrations\WorkspaceDaemon\WorkspaceDaemonTransport;
use App\Jobs\Workspace\DrainSessionQueueJob;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Jobs\Workspace\ReconcileTaskSessionsJob;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\Workspace;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\TaskSessionReconciler;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/** Sessions the daemon lost (restart, crash) are closed in the manager too. */
class TaskSessionReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DrainSessionQueueJob::class, PrepareTaskJob::class]);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function task(array $attributes = []): Task
    {
        $task = Task::factory()->open()->create(['workspace_id' => $this->workspace->id, ...$attributes]);
        Task::query()->whereKey($task->id)->update(['updated_at' => now()->subMinutes(5)]);

        return $task->fresh();
    }

    private function reconciler(): TaskSessionReconciler
    {
        return app(TaskSessionReconciler::class);
    }

    public function test_a_session_the_daemon_lost_is_closed_with_its_approvals_and_a_notice(): void
    {
        $lost = $this->task(['session_status' => SessionStatus::WaitingInput]);
        $approval = TaskApproval::factory()->create(['task_id' => $lost->id]);
        $alive = $this->task(['session_status' => SessionStatus::Running]);

        $closed = $this->reconciler()->reconcile($this->workspace, ['tasks' => [['id' => $alive->id, 'state' => 'running']]]);

        $this->assertSame(1, $closed);
        $lost->refresh();
        $this->assertFalse($lost->session_open);
        $this->assertSame(SessionStatus::Closed, $lost->session_status);
        $this->assertSame(ApprovalStatus::Cancelled, $approval->fresh()->status);
        $this->assertTrue(TaskEvent::query()->where('task_id', $lost->id)->where('type', TaskEventType::Notice->value)
            ->get()->contains(fn (TaskEvent $e): bool => ($e->payload['kind'] ?? null) === 'session_lost'));
        $this->assertTrue($alive->fresh()->session_open);
        Queue::assertPushed(DrainSessionQueueJob::class);
    }

    public function test_an_idle_lost_session_closes_quietly(): void
    {
        $idle = $this->task();

        $this->reconciler()->reconcile($this->workspace, ['tasks' => []]);

        $this->assertFalse($idle->fresh()->session_open);
        $this->assertSame(0, TaskEvent::query()->where('task_id', $idle->id)->count());
    }

    public function test_a_session_that_is_just_starting_is_left_alone(): void
    {
        $starting = Task::factory()->open()->create(['workspace_id' => $this->workspace->id, 'session_status' => SessionStatus::Starting]);

        $this->assertSame(0, $this->reconciler()->reconcile($this->workspace, ['tasks' => []]));
        $this->assertTrue($starting->fresh()->session_open);
    }

    public function test_a_new_daemon_instance_restarts_cut_preparations(): void
    {
        $preparing = Task::factory()->create(['workspace_id' => $this->workspace->id, 'status' => TaskStatus::Preparing]);

        $this->reconciler()->reconcile($this->workspace, ['boot_id' => 'a', 'tasks' => []]);
        $this->reconciler()->reconcile($this->workspace, ['boot_id' => 'a', 'tasks' => []]);
        Queue::assertNotPushed(PrepareTaskJob::class);

        $this->reconciler()->reconcile($this->workspace, ['boot_id' => 'b', 'tasks' => []]);
        Queue::assertPushed(PrepareTaskJob::class, fn (PrepareTaskJob $job): bool => $job->taskId === $preparing->id);
    }

    public function test_after_a_boot_no_session_counts_as_open(): void
    {
        $fresh = Task::factory()->open()->create(['workspace_id' => $this->workspace->id, 'session_status' => SessionStatus::Running]);

        $this->reconciler()->afterBoot($this->workspace, ['boot_id' => 'x', 'tasks' => []]);

        $this->assertFalse($fresh->fresh()->session_open);
    }

    public function test_stopping_a_session_the_daemon_no_longer_knows_closes_it(): void
    {
        $task = $this->task(['session_status' => SessionStatus::Running]);
        $this->mock(WorkspaceDaemonClient::class, function (MockInterface $mock): void {
            $api = \Mockery::mock(WorkspaceDaemonTransport::class);
            $api->shouldReceive('interrupt')->andThrow(new DaemonTransportException('Task is not running', 404, 'unknown_task'));
            $mock->shouldReceive('for')->andReturn($api);
        });

        app(TaskService::class)->interrupt($task, $task->owner);

        $this->assertFalse($task->fresh()->session_open);
    }

    public function test_the_sweep_asks_each_running_daemon(): void
    {
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $lost = $this->task();

        (new ReconcileTaskSessionsJob)->handle(app(WorkspaceDaemonClient::class), $this->reconciler());

        $this->assertFalse($lost->fresh()->session_open);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\AutoTaskState;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\Requests\PrepareWorktree;
use App\Integrations\WorkspaceDaemon\Requests\StartTask;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Worker\WorkerSidecarManager;
use App\Services\Workspace\AutoTaskDriver;
use App\Services\Workspace\AutoTaskRetry;
use App\Services\Workspace\TaskPreparer;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

/**
 * A new task gets its own worktree, database and setup run before Claude
 * starts (status preparing → active), see TaskPreparer.
 */
class TaskPreparationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private AgentCredential $agent;

    /** @var list<array{0: Workspace, 1: string}> databases created on the MySQL sidecar */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Admin]);
        $this->agent = AgentCredential::factory()->ownedBy($this->owner)->create(['credentials' => ['token' => 'oat'], 'max_concurrent_sessions' => 5]);

        $sidecars = Mockery::mock(WorkerSidecarManager::class);
        $sidecars->shouldReceive('createTaskDatabase')->andReturnUsing(function (Workspace $workspace, string $database): void {
            $this->databases[] = [$workspace, $database];
        });
        $this->app->instance(WorkerSidecarManager::class, $sidecars);
    }

    /** @param  array<string, mixed>  $attributes */
    private function preparingTask(array $attributes = []): Task
    {
        return Task::factory()->ownedBy($this->owner)->create([
            'workspace_id' => $this->workspace->id,
            'agent_credential_id' => $this->agent->id,
            'slug' => 'fix-login',
            'branch' => 'argos/fix-login',
            'status' => TaskStatus::Preparing,
            ...$attributes,
        ]);
    }

    private function prepare(Task $task): bool
    {
        return app(TaskPreparer::class)->prepare($task->fresh());
    }

    /** @return list<array<string, mixed>> */
    private function notices(Task $task): array
    {
        return TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::Notice->value)->orderBy('id')->pluck('payload')->all();
    }

    public function test_a_new_task_is_preparing_and_queues_its_preparation(): void
    {
        Bus::fake([PrepareTaskJob::class]);

        $task = app(TaskService::class)->create($this->workspace, $this->owner, $this->agent, 'Fix login');

        $this->assertSame(TaskStatus::Preparing, $task->status);
        Bus::assertDispatched(PrepareTaskJob::class, fn (PrepareTaskJob $job): bool => $job->taskId === $task->id);
    }

    public function test_a_stopped_workspace_is_started_and_the_preparation_retried(): void
    {
        Bus::fake([ProvisionWorkspaceJob::class]);
        $this->workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();
        $task = $this->preparingTask();

        $job = (new PrepareTaskJob($task->id))->withFakeQueueInteractions();
        $job->handle(app(TaskPreparer::class));

        $job->assertReleased(TaskPreparer::RETRY_SECONDS);
        $this->assertSame(WorkspaceStatus::Starting, $this->workspace->fresh()->status);
        Bus::assertDispatched(ProvisionWorkspaceJob::class);
        $this->assertSame([], FakeWorkspaceDaemon::$prepared);
        $this->assertSame(TaskStatus::Preparing, $task->fresh()->status);
    }

    public function test_a_starting_workspace_is_waited_for(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $task = $this->preparingTask();

        $this->assertFalse($this->prepare($task));
        $this->assertSame([], FakeWorkspaceDaemon::$prepared);
    }

    public function test_the_task_gets_its_own_database_and_environment(): void
    {
        $this->workspace->forceFill(['worker_services' => ['mysql', 'redis']])->save();
        $task = $this->preparingTask(['base_branch' => 'develop', 'last_seq' => 3]);

        $this->assertTrue($this->prepare($task));

        $this->assertCount(1, $this->databases);
        $this->assertTrue($this->databases[0][0]->is($this->workspace));
        $this->assertSame('task_fix_login', $this->databases[0][1]);
        $this->assertSame('task_fix_login', $task->fresh()->db_name);

        $payload = FakeWorkspaceDaemon::$prepared['fix-login'];
        $this->assertSame($task->id, $payload['task_id']);
        $this->assertSame('argos/fix-login', $payload['branch']);
        $this->assertSame('develop', $payload['base_branch']);
        $this->assertSame(3, $payload['seq_offset']);
        $this->assertSame('mysql', $payload['env']['DB_CONNECTION']);
        $this->assertSame('task_fix_login', $payload['env']['DB_DATABASE']);
        $this->assertSame('fix_login_', $payload['env']['REDIS_PREFIX']);
        $this->assertSame('fix_login_', $payload['env']['CACHE_PREFIX']);
    }

    public function test_completion_activates_the_task_and_shows_the_setup_in_the_chat(): void
    {
        FakeWorkspaceDaemon::$prepareOutcome = 'setup_failed';
        $task = $this->preparingTask();

        $this->prepare($task);

        $task->refresh();
        $this->assertSame(TaskStatus::Active, $task->status);
        $this->assertSame('/workspace/tasks/fix-login', $task->worktree_path);
        $this->assertSame(str_repeat('b', 40), $task->base_commit);
        // Three prepare.* events went through the regular ingest.
        $this->assertSame(3, $task->last_seq);

        $notices = $this->notices($task);
        $this->assertSame(['prepare_step', 'prepare_step', 'prepared'], array_column($notices, 'kind'));
        $this->assertSame('failed', $notices[2]['setup']);
        $this->assertStringContainsString('composer install', $notices[2]['log']);

        Livewire::actingAs($this->owner)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $task->id)
            ->assertSee(__('workspaces.events.prepare_step.worktree'))
            ->assertSee(__('workspaces.events.prepare_step.setup'))
            ->assertSee(__('workspaces.events.prepared_setup_failed'))
            ->assertSee(__('workspaces.events.prepared_log'))
            ->assertSee('[setup] composer install');
    }

    public function test_a_successful_setup_says_claude_can_start(): void
    {
        $task = $this->preparingTask();

        $this->prepare($task);

        $this->assertSame('ok', $this->notices($task)[2]['setup']);
        Livewire::actingAs($this->owner)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $task->id)
            ->assertSee(__('workspaces.events.prepared'));
    }

    public function test_a_failed_preparation_keeps_the_task_preparing_and_says_why(): void
    {
        FakeWorkspaceDaemon::$prepareOutcome = 'failed';
        $task = $this->preparingTask();

        $this->prepare($task);

        $task->refresh();
        $this->assertSame(TaskStatus::Preparing, $task->status);
        $this->assertNull($task->base_commit);
        $failed = collect($this->notices($task))->firstWhere('kind', 'prepare_failed');
        $this->assertSame('Base branch does not exist on the remote', $failed['message']);

        Livewire::actingAs($this->owner)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $task->id)
            ->assertSee(__('workspaces.events.prepare_failed', ['message' => 'Base branch does not exist on the remote']));
    }

    public function test_a_failed_preparation_can_be_tried_again_from_the_chat(): void
    {
        FakeWorkspaceDaemon::$prepareOutcome = 'failed';
        $task = $this->preparingTask();
        $this->prepare($task);

        FakeWorkspaceDaemon::$prepareOutcome = 'ok';
        Livewire::actingAs($this->owner)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $task->id)
            ->assertSee(__('workspaces.events.prepare_retry'))
            ->call('retryPreparation');

        $this->assertSame(TaskStatus::Active, $task->fresh()->status);
    }

    public function test_a_workspace_without_mysql_gets_no_task_database(): void
    {
        $task = $this->preparingTask();

        $this->prepare($task);

        $this->assertSame([], $this->databases);
        $this->assertNull($task->fresh()->db_name);
    }

    public function test_a_workspace_without_services_sends_an_empty_env_object(): void
    {
        $this->app->forgetInstance(WorkspaceDaemonClient::class);
        $this->app->offsetUnset(WorkspaceDaemonClient::class);
        $body = null;
        Saloon::fake([PrepareWorktree::class => function (PendingRequest $request) use (&$body) {
            $body = (string) $request->body();

            return MockResponse::make(['status' => 'preparing'], 202);
        }]);
        $task = $this->preparingTask();
        $task->workspace->forceFill(['worker_services' => []])->save();

        $this->assertTrue($this->prepare($task->fresh()));

        // The daemon expects an object; PHP would send an empty array as [].
        $this->assertStringContainsString('"env":{}', (string) $body);
    }

    public function test_an_unreachable_daemon_is_reported_as_a_failed_preparation(): void
    {
        $this->app->forgetInstance(WorkspaceDaemonClient::class);
        $this->app->offsetUnset(WorkspaceDaemonClient::class);
        $url = null;
        Saloon::fake([PrepareWorktree::class => function (PendingRequest $request) use (&$url) {
            $url = $request->getUrl();

            return MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'git worktree add failed']], 500);
        }]);
        $task = $this->preparingTask();

        $this->assertTrue($this->prepare($task));

        $this->assertStringEndsWith('/worktrees/fix-login/prepare', (string) $url);
        $this->assertSame(TaskStatus::Preparing, $task->fresh()->status);
        $this->assertSame('prepare_failed', $this->notices($task)[0]['kind']);
        $this->assertStringContainsString('git worktree add failed', $this->notices($task)[0]['message']);
    }

    public function test_an_auto_task_begins_once_its_working_copy_is_ready(): void
    {
        Bus::fake([PrepareTaskJob::class, AdvanceAutoTaskJob::class]);
        $driver = app(AutoTaskDriver::class);
        $task = $driver->start($this->workspace, $this->owner, $this->agent, 'Add a priority field', null, null, ['plan' => 'off']);
        $this->assertSame(TaskStatus::Preparing, $task->fresh()->status);

        // While preparing, the first step waits.
        try {
            $driver->step($task->id, 'begin');
            $this->fail('expected AutoTaskRetry');
        } catch (AutoTaskRetry $retry) {
            $this->assertFalse($retry->giveUp);
        }
        $this->assertSame(AutoTaskState::Queued, $task->fresh()->auto_state);
        $this->assertSame(0, TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->count());

        // Preparation completes: the run is kicked off again.
        $before = count(Bus::dispatched(AdvanceAutoTaskJob::class));
        $this->prepare($task);
        $this->assertSame($before + 1, count(Bus::dispatched(AdvanceAutoTaskJob::class)));
        Bus::assertDispatched(AdvanceAutoTaskJob::class, fn (AdvanceAutoTaskJob $job): bool => $job->step === 'begin' && $job->taskId === $task->id);

        // The fake daemon finishes the first turn right away, so the run moves on to the gates.
        $driver->step($task->id, 'begin');
        $this->assertSame(AutoTaskState::Gating, $task->fresh()->auto_state);
        $this->assertSame(1, TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->count());
    }

    public function test_a_manual_task_is_not_kicked_off_after_preparation(): void
    {
        Bus::fake([AdvanceAutoTaskJob::class]);

        $this->prepare($this->preparingTask());

        Bus::assertNotDispatched(AdvanceAutoTaskJob::class);
    }

    public function test_nobody_can_open_or_write_while_the_task_is_preparing(): void
    {
        $task = $this->preparingTask();

        foreach ([
            fn () => app(TaskService::class)->open($task),
            fn () => app(TaskService::class)->send($task, $this->owner, 'hello'),
        ] as $call) {
            try {
                $call();
                $this->fail('expected WorkspaceException');
            } catch (WorkspaceException $e) {
                $this->assertSame('preparing', $e->reason);
                $this->assertSame(__('workspaces.errors.preparing'), $e->getMessage());
            }
        }

        $this->assertFalse($task->fresh()->session_open);
        $this->assertSame(0, TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->count());
    }

    public function test_the_session_starts_in_the_task_worktree_with_the_right_guard(): void
    {
        $this->app->forgetInstance(WorkspaceDaemonClient::class);
        $this->app->offsetUnset(WorkspaceDaemonClient::class);
        $bodies = [];
        Saloon::fake([StartTask::class => function (PendingRequest $request) use (&$bodies) {
            $bodies[] = $request->body()->all();

            return MockResponse::make(['state' => 'starting']);
        }]);
        $manual = $this->preparingTask(['status' => TaskStatus::Active]);
        $auto = $this->preparingTask(['slug' => 'auto-run', 'status' => TaskStatus::Active, 'auto_state' => AutoTaskState::Implementing]);
        $paused = $this->preparingTask(['slug' => 'paused-run', 'status' => TaskStatus::Active, 'auto_state' => AutoTaskState::Paused]);

        app(TaskService::class)->open($manual);
        app(TaskService::class)->open($auto);
        app(TaskService::class)->open($paused);

        $this->assertSame(['fix-login', 'ask'], [$bodies[0]['worktree'], $bodies[0]['guard']]);
        // Nobody answers in a running automatic task: out-of-folder access is refused outright.
        $this->assertSame(['auto-run', 'deny'], [$bodies[1]['worktree'], $bodies[1]['guard']]);
        $this->assertSame(['paused-run', 'ask'], [$bodies[2]['worktree'], $bodies[2]['guard']]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\ApprovalStatus;
use App\Enums\AutoTaskState;
use App\Enums\GitProvider;
use App\Enums\PermissionMode;
use App\Enums\TaskEventType;
use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\Workspace\AutoTaskDriver;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AutoTaskDriverTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private AgentCredential $agent;

    private GitServiceContract&MockInterface $git;

    protected function setUp(): void
    {
        parent::setUp();
        // Run the driver's steps inline: the whole run plays out in the test.
        config(['queue.default' => 'sync']);
        Event::fake([TaskStream::class]);
        Notification::fake();
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);

        $this->workspace = Workspace::factory()->create(['repo_url' => 'https://github.com/acme/widget', 'platform' => GitProvider::GitHub, 'status' => WorkspaceStatus::Running]);
        $this->owner = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Admin]);
        $this->agent = AgentCredential::factory()->ownedBy($this->owner)->create(['credentials' => ['token' => 'oat']]);

        $this->git = Mockery::mock(GitServiceContract::class);
        $this->git->shouldReceive('createPullRequest')->andReturn(['number' => 7, 'html_url' => 'https://github.com/acme/widget/pull/7', 'state' => 'open'])->byDefault();
        $this->mock(GitServiceFactory::class)->shouldReceive('forWorkspace')->andReturn($this->git);
    }

    protected function tearDown(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function start(array $overrides = []): Task
    {
        return app(AutoTaskDriver::class)->start($this->workspace, $this->owner, $this->agent, 'Add a priority field to notes', null, null, $overrides)->fresh();
    }

    private function messages(Task $task): array
    {
        return TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->orderBy('id')->get()->all();
    }

    public function test_without_a_plan_it_implements_fixes_the_gates_and_ships(): void
    {
        $task = $this->start(['plan' => 'off', 'max_fix_rounds' => 2]);

        $this->assertSame(AutoTaskState::Done, $task->auto_state);
        $this->assertSame(PermissionMode::BypassPermissions, $task->permission_mode);
        $this->assertSame(2, $task->auto_counters['fix_rounds']);
        $this->assertFalse($task->session_open);

        $messages = $this->messages($task);
        $this->assertCount(3, $messages, 'task + 2 fix prompts');
        $this->assertStringContainsString('Add a priority field to notes', $messages[0]->payload['text']);
        $this->assertTrue($messages[0]->payload['auto']);
        $this->assertStringContainsString('Quality-Gate-Fix: pest', $messages[1]->payload['text']);
        $this->assertSame(3, WorkspaceGateRun::query()->count());
        $this->assertSame(7, $task->pr_number);
        $this->assertSame(TaskStatus::PrOpen, $task->status);
    }

    public function test_the_task_belongs_to_the_starter_and_ships_its_own_branch(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body, $head, $base) => $head === 'argos/priority-field' && $base === 'develop' && str_contains($body, 'Ada'))
            ->andReturn(['number' => 9, 'html_url' => 'https://x/9', 'state' => 'open']);

        $task = app(AutoTaskDriver::class)->start($this->workspace, $this->owner, $this->agent, 'Add a priority field to notes', 'Priority field', null, ['plan' => 'off'], ['base_branch' => 'develop'])->fresh();

        $this->assertTrue($task->isOwnedBy($this->owner));
        $this->assertSame('argos/priority-field', $task->branch);
        $this->assertSame('develop', $task->base_branch);
        $this->assertSame(AutoTaskState::Done, $task->auto_state);
        $this->assertSame(9, $task->pr_number);
        $this->assertSame(TaskStatus::PrOpen, $task->status);
    }

    public function test_green_gates_ship_right_away_and_the_pr_mentions_them(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body) => str_contains($body, '3/3'))
            ->andReturn(['number' => 8, 'html_url' => 'https://x/8', 'state' => 'open']);

        $task = $this->start(['plan' => 'off']);

        $this->assertSame(AutoTaskState::Done, $task->auto_state);
        $this->assertSame(0, $task->auto_counters['fix_rounds']);
    }

    public function test_an_automatic_plan_is_accepted_and_implemented_in_bypass_mode(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';

        $task = $this->start(['plan' => 'auto']);

        $this->assertSame(AutoTaskState::Done, $task->auto_state);
        $this->assertSame(PermissionMode::BypassPermissions, $task->permission_mode);
        $plan = TaskApproval::query()->where('task_id', $task->id)->sole();
        $this->assertSame(ApprovalStatus::Allowed, $plan->status);
        $this->assertStringContainsString('ExitPlanMode', $this->messages($task)[0]->payload['text']);
    }

    public function test_a_plan_waits_for_approval_and_can_be_rejected_or_approved(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        $task = $this->start(['plan' => 'approve']);

        $this->assertSame(AutoTaskState::AwaitingPlanApproval, $task->auto_state);
        $this->assertSame(PermissionMode::Plan, $task->permission_mode);

        $driver = app(AutoTaskDriver::class);
        $driver->decidePlan($task, $this->owner, false, 'Use an enum for the priority.');
        $task->refresh();
        $this->assertSame(AutoTaskState::AwaitingPlanApproval, $task->auto_state, 'the revised plan waits again');
        $this->assertSame(2, TaskApproval::query()->where('task_id', $task->id)->count());

        $driver->decidePlan($task, $this->owner, true);
        $this->assertSame(AutoTaskState::Done, $task->fresh()->auto_state);
    }

    public function test_a_human_message_pauses_and_resume_continues_with_the_gates(): void
    {
        Queue::fake();
        $task = $this->start(['plan' => 'off']);
        $task->forceFill(['auto_state' => AutoTaskState::Implementing])->save();
        app(TaskService::class)->open($task);

        app(TaskService::class)->send($task, $this->owner, 'Also add a test for the default');

        $task->refresh();
        $this->assertSame(AutoTaskState::Paused, $task->auto_state);
        $this->assertStringContainsString('Ada', (string) $task->auto_message);

        app(AutoTaskDriver::class)->resume($task, $this->owner);
        $this->assertSame(AutoTaskState::Gating, $task->fresh()->auto_state);
        Queue::assertPushed(AdvanceAutoTaskJob::class, fn (AdvanceAutoTaskJob $job): bool => $job->step === 'gate');
    }

    public function test_the_turn_budget_stops_the_run(): void
    {
        $task = $this->start(['plan' => 'off', 'max_turns' => 1]);

        $this->assertSame(AutoTaskState::BudgetExceeded, $task->auto_state);
        $this->assertStringContainsString('1', (string) $task->auto_message);
        $this->assertSame(0, WorkspaceGateRun::query()->count());
    }

    public function test_questions_are_answered_with_decide_yourself(): void
    {
        // A starting workspace keeps the run queued, so nothing else happens.
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $task = $this->start(['plan' => 'off']);
        $this->workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $task->forceFill(['auto_state' => AutoTaskState::Implementing])->save();
        app(TaskService::class)->open($task);

        app(TaskEventIngestor::class)->ingest($this->workspace, [[
            'task_id' => $task->id, 'seq' => 50, 'type' => 'approval.requested',
            'data' => ['approval_id' => '01JQUESTION0000000000000000', 'kind' => 'question', 'tool_name' => 'AskUserQuestion', 'input' => ['questions' => []]],
        ]]);

        $question = TaskApproval::query()->whereKey('01JQUESTION0000000000000000')->sole();
        $this->assertSame(ApprovalStatus::Denied, $question->status);
        $this->assertStringContainsString('automatic', (string) ($question->response['message'] ?? ''));
    }

    public function test_a_repeated_turn_completed_does_not_run_the_gates_twice(): void
    {
        Queue::fake();
        $task = $this->start(['plan' => 'off']);
        $task->forceFill(['auto_state' => AutoTaskState::Implementing])->save();
        $driver = app(AutoTaskDriver::class);

        $driver->onTaskEvents($task->fresh(), [['type' => 'turn.completed', 'data' => []]]);
        $driver->onTaskEvents($task->fresh(), [['type' => 'turn.completed', 'data' => []]]);

        Queue::assertPushed(AdvanceAutoTaskJob::class, fn (AdvanceAutoTaskJob $job): bool => $job->step === 'gate');
        $this->assertSame(1, collect(Queue::pushed(AdvanceAutoTaskJob::class))->filter(fn ($job) => $job->step === 'gate')->count());
    }

    public function test_it_waits_while_the_workspace_starts(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();

        $task = $this->start(['plan' => 'off']);

        $this->assertSame(AutoTaskState::Queued, $task->auto_state);
        $this->assertCount(0, $this->messages($task));
    }

    public function test_a_finished_auto_task_leaves_the_workspace_running(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        // Only the stop job is faked; the auto-task steps keep running inline.
        Queue::fake([StopWorkspaceJob::class]);

        $task = app(AutoTaskDriver::class)->start($this->workspace, $this->owner, $this->agent, 'Fix it', null, null, ['plan' => 'off'], ['origin' => TaskOrigin::Api])->fresh();

        $this->assertSame(AutoTaskState::Done, $task->auto_state);
        $this->assertSame(TaskOrigin::Api, $task->origin);
        Queue::assertNotPushed(StopWorkspaceJob::class);
        $this->assertSame(WorkspaceStatus::Running, $this->workspace->fresh()->status);
    }

    public function test_a_usage_limit_continues_the_run_once_lifted(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $task = $this->start(['plan' => 'off']);
        $task->forceFill(['auto_state' => AutoTaskState::Implementing])->save();
        Queue::fake([AdvanceAutoTaskJob::class]);

        app(AutoTaskDriver::class)->onTaskEvents($task->fresh(), [['type' => 'usage_limit', 'data' => ['resets_at' => now()->addHour()->toIso8601String()]]]);

        Queue::assertPushed(AdvanceAutoTaskJob::class, fn (AdvanceAutoTaskJob $job): bool => $job->step === 'continue' && $job->delay !== null);
        $this->assertSame(AutoTaskState::Implementing, $task->fresh()->auto_state);
        $this->assertNotNull($task->fresh()->auto_message);
    }

    public function test_an_error_fails_the_run_and_tells_the_members(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $task = $this->start(['plan' => 'off']);
        $task->forceFill(['auto_state' => AutoTaskState::Implementing])->save();

        app(AutoTaskDriver::class)->onTaskEvents($task->fresh(), [['type' => 'error', 'data' => ['code' => 'session_failed', 'message' => 'claude crashed']]]);

        $this->assertSame(AutoTaskState::Failed, $task->fresh()->auto_state);
        $this->assertSame('claude crashed', $task->fresh()->auto_message);
        Notification::assertSentTo($this->owner, DatabaseNotification::class);
    }
}

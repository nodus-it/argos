<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\GateRunStatus;
use App\Enums\TaskEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Livewire\Workspace\ContextPanel;
use App\Livewire\Workspace\GateDetail;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceQualityGateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $editor;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->editor = $this->member(WorkspaceRole::Member, 'Ada');
        $this->task = Task::factory()->ownedBy($this->editor)->create(['workspace_id' => $this->workspace->id, 'base_commit' => str_repeat('c', 40)]);
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function service(): WorkspaceGateService
    {
        return app(WorkspaceGateService::class);
    }

    /** @param  list<array{0: string, 1: array<string, mixed>}>  $events */
    private function ingest(array $events): void
    {
        $seq = 0;
        app(TaskEventIngestor::class)->ingest($this->workspace, array_map(
            fn (array $e): array => ['task_id' => null, 'seq' => ++$seq, 'type' => $e[0], 'data' => $e[1]],
            $events,
        ));
    }

    public function test_a_run_records_the_reported_gates_and_logs(): void
    {
        $run = $this->service()->run($this->task, $this->editor);

        $this->assertSame($this->task->id, $run->task_id);
        $this->assertSame($this->workspace->id, $run->workspace_id);
        $this->assertSame(str_repeat('c', 40), $run->base_commit);
        $this->assertSame(GateRunStatus::Failed, $run->status);
        $this->assertSame(['artisan' => 'pass', 'pint' => 'pass', 'pest' => 'fail', 'phpstan' => 'skip'], $run->gates);
        $this->assertStringContainsString('LoginTest', $run->logs['pest']);
        $this->assertSame(['pest'], $run->failedGates());
        $this->assertSame('2/3', $this->service()->summary($this->task));
        $this->assertSame($run->id, $this->service()->latest($this->task)?->id);
        $this->assertSame($this->editor->id, $run->triggered_by);
        Event::assertDispatched(TaskStream::class, fn (TaskStream $e): bool => $e->taskId === TaskEventIngestor::WORKSPACE_KEY);
    }

    public function test_viewers_cannot_run_gates(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service()->run($this->task, $this->member(WorkspaceRole::Viewer, 'Vi'));
    }

    public function test_members_cannot_run_gates_in_someone_elses_task(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service()->run($this->task, $this->member(WorkspaceRole::Member, 'Bo'));
    }

    public function test_runs_and_summaries_belong_to_their_task(): void
    {
        $other = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        WorkspaceGateRun::factory()->create(['task_id' => $other->id, 'status' => GateRunStatus::Failed, 'gates' => ['pest' => 'fail', 'pint' => 'pass']]);

        $this->assertNull($this->service()->latest($this->task));
        $this->assertNull($this->service()->summary($this->task));
        $this->assertSame('1/2', $this->service()->summary($other));
    }

    public function test_only_one_run_at_a_time(): void
    {
        // One container: a run of another task blocks too.
        WorkspaceGateRun::factory()->create(['task_id' => Task::factory()->create(['workspace_id' => $this->workspace->id])->id, 'status' => GateRunStatus::Running, 'gates' => null]);

        $this->expectException(WorkspaceException::class);
        $this->service()->run($this->task, $this->editor);
    }

    public function test_a_lost_run_no_longer_blocks(): void
    {
        $lost = WorkspaceGateRun::factory()->create(['task_id' => $this->task->id, 'status' => GateRunStatus::Running, 'gates' => null]);
        $lost->forceFill(['created_at' => now()->subHour()])->save();

        $this->service()->run($this->task, $this->editor);

        $this->assertSame(GateRunStatus::Error, $lost->fresh()->status);
    }

    public function test_a_busy_daemon_marks_the_run_as_error(): void
    {
        $daemon = $this->mock(WorkspaceDaemonClient::class);
        $daemon->shouldReceive('for->runGates')->andThrow(new DaemonTransportException('busy', 409, 'gates_running'));

        try {
            $this->service()->run($this->task, $this->editor);
            $this->fail('expected an exception');
        } catch (WorkspaceException $e) {
            $this->assertSame(__('workspaces.gates.already_running'), $e->getMessage());
        }
        $this->assertSame(GateRunStatus::Error, WorkspaceGateRun::query()->sole()->status);
    }

    public function test_events_are_applied_once_per_run(): void
    {
        $run = WorkspaceGateRun::factory()->create(['task_id' => $this->task->id, 'status' => GateRunStatus::Running, 'gates' => null]);
        $completed = ['gates.completed', ['run_id' => $run->id, 'gates' => ['pint' => 'pass', 'evil' => 'maybe'], 'logs' => ['pint' => 'ok', 'other' => 'x']]];

        $this->ingest([$completed]);
        $this->assertSame(GateRunStatus::Passed, $run->fresh()->status);
        $this->assertSame(['pint' => 'pass'], $run->fresh()->gates);
        $this->assertSame(['pint' => 'ok'], $run->fresh()->logs);

        // A re-delivered failure for the finished run changes nothing.
        $this->ingest([['gates.failed', ['run_id' => $run->id, 'message' => 'late']]]);
        $this->assertSame(GateRunStatus::Passed, $run->fresh()->status);
    }

    public function test_runs_of_other_workspaces_are_ignored(): void
    {
        $foreign = WorkspaceGateRun::factory()->create(['status' => GateRunStatus::Running, 'gates' => null]);

        $this->ingest([['gates.failed', ['run_id' => $foreign->id, 'message' => 'x']]]);

        $this->assertSame(GateRunStatus::Running, $foreign->fresh()->status);
    }

    public function test_the_baseline_state_is_tracked_per_base_commit(): void
    {
        $base = str_repeat('c', 40);
        $this->ingest([['gates.baseline', ['state' => 'running', 'commit' => $base]], ['gates.baseline', ['state' => 'ready', 'commit' => $base]], ['gates.baseline', ['state' => 'failed']]]);
        $this->assertSame([$base => 'ready', WorkspaceGateService::CURRENT_BASE => 'failed'], $this->workspace->fresh()->gate_baselines);

        $this->ingest([['gates.baseline', ['state' => 'bogus', 'commit' => $base]]]);
        $this->assertSame('ready', $this->service()->baselineFor($this->workspace->fresh(), $base));

        // A run remembers the baseline of its task's base commit.
        $this->assertSame('ready', $this->service()->run($this->task->fresh(), $this->editor)->baseline);
    }

    public function test_the_review_tab_shows_results_and_sends_fixes_to_the_agent(): void
    {
        // The fake daemon prepares the new task inline.
        config(['queue.default' => 'sync']);
        AgentCredential::factory()->ownedBy($this->editor)->create();
        Livewire::actingAs($this->editor)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->call('startCreating')->set('newTitle', 'Gates')->call('createTask')->assertHasNoErrors();
        $task = Task::query()->where('title', 'Gates')->sole();

        Livewire::withoutLazyLoading()->withQueryParams(['task' => $task->id])->actingAs($this->editor)->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(__('workspaces.focus.gate_state.open'))
            ->call('runGates')
            ->assertSee(__('workspaces.focus.gate_state.fail'));

        $detail = Livewire::actingAs($this->editor)->test(GateDetail::class, ['workspaceId' => $this->workspace->id])
            ->call('show', $task->id, 'pest')
            ->assertSee(__('workspaces.gates.send_fix'))
            ->assertSee('LoginTest');

        $detail->call('sendGateFix', 'pest')->assertNotified(__('workspaces.gates.fix_sent'));
        $message = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->sole();
        $this->assertStringContainsString('# Quality-Gate-Fix: pest', $message->payload['text']);

        // Only failed gates can be sent.
        $detail->call('sendGateFix', 'pint')->assertNotified(__('workspaces.gates.no_prompt'));
        $this->assertSame(1, TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->count());
    }

    public function test_viewers_see_results_without_actions(): void
    {
        WorkspaceGateRun::factory()->create(['task_id' => $this->task->id, 'status' => GateRunStatus::Failed, 'gates' => ['pest' => 'fail']]);

        Livewire::withoutLazyLoading()->actingAs($this->member(WorkspaceRole::Viewer, 'Vi'))->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('Pest / PHPUnit')
            ->assertSee(__('workspaces.focus.gate_state.fail'))
            ->assertDontSee(__('workspaces.gates.send_fix'))
            ->assertDontSee(__('workspaces.focus.check_all'));
    }

    public function test_the_header_action_runs_the_gates(): void
    {
        Livewire::actingAs($this->editor)->test(ViewWorkspace::class, ['record' => $this->workspace->id])
            ->callAction('gates')
            ->assertNotified(__('workspaces.gates.started'))
            ->assertDispatched('workspace-focus', target: 'gates');

        $run = WorkspaceGateRun::query()->sole();
        $this->assertSame(GateRunStatus::Failed, $run->status);
        $this->assertSame($this->task->id, $run->task_id);
        $this->assertSame('2/3', $this->service()->summary($this->task));
    }
}

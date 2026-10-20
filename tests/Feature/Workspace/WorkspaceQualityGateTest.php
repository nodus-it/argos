<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\GateRunStatus;
use App\Enums\ThreadEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Livewire\Workspace\ReviewPanel;
use App\Livewire\Workspace\ThreadChat;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\Workspace\ThreadEventIngestor;
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

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Event::fake([ThreadStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->editor = $this->member(WorkspaceRole::Editor, 'Ada');
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
        app(ThreadEventIngestor::class)->ingest($this->workspace, array_map(
            fn (array $e): array => ['thread_id' => null, 'seq' => ++$seq, 'type' => $e[0], 'data' => $e[1]],
            $events,
        ));
    }

    public function test_a_run_records_the_reported_gates_and_logs(): void
    {
        $run = $this->service()->run($this->workspace, $this->editor);

        $this->assertSame(GateRunStatus::Failed, $run->status);
        $this->assertSame(['artisan' => 'pass', 'pint' => 'pass', 'pest' => 'fail', 'phpstan' => 'skip'], $run->gates);
        $this->assertStringContainsString('LoginTest', $run->logs['pest']);
        $this->assertSame(['pest'], $run->failedGates());
        $this->assertSame('2/3', $this->service()->summary($this->workspace));
        $this->assertSame($this->editor->id, $run->triggered_by);
        Event::assertDispatched(ThreadStream::class, fn (ThreadStream $e): bool => $e->threadId === ThreadEventIngestor::WORKSPACE_KEY);
    }

    public function test_viewers_cannot_run_gates(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service()->run($this->workspace, $this->member(WorkspaceRole::Viewer, 'Vi'));
    }

    public function test_only_one_run_at_a_time(): void
    {
        WorkspaceGateRun::factory()->create(['workspace_id' => $this->workspace->id, 'status' => GateRunStatus::Running, 'gates' => null]);

        $this->expectException(WorkspaceException::class);
        $this->service()->run($this->workspace, $this->editor);
    }

    public function test_a_lost_run_no_longer_blocks(): void
    {
        $lost = WorkspaceGateRun::factory()->create(['workspace_id' => $this->workspace->id, 'status' => GateRunStatus::Running, 'gates' => null]);
        $lost->forceFill(['created_at' => now()->subHour()])->save();

        $this->service()->run($this->workspace, $this->editor);

        $this->assertSame(GateRunStatus::Error, $lost->fresh()->status);
    }

    public function test_a_busy_daemon_marks_the_run_as_error(): void
    {
        $daemon = $this->mock(WorkspaceDaemonClient::class);
        $daemon->shouldReceive('for->runGates')->andThrow(new DaemonTransportException('busy', 409, 'gates_running'));

        try {
            $this->service()->run($this->workspace, $this->editor);
            $this->fail('expected an exception');
        } catch (WorkspaceException $e) {
            $this->assertSame(__('workspaces.gates.already_running'), $e->getMessage());
        }
        $this->assertSame(GateRunStatus::Error, WorkspaceGateRun::query()->sole()->status);
    }

    public function test_events_are_applied_once_per_run(): void
    {
        $run = WorkspaceGateRun::factory()->create(['workspace_id' => $this->workspace->id, 'status' => GateRunStatus::Running, 'gates' => null]);
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

    public function test_the_baseline_state_is_tracked(): void
    {
        $this->ingest([['gates.baseline', ['state' => 'running']], ['gates.baseline', ['state' => 'ready']]]);
        $this->assertSame('ready', $this->workspace->fresh()->gate_baseline);

        $this->ingest([['gates.baseline', ['state' => 'bogus']]]);
        $this->assertSame('ready', $this->workspace->fresh()->gate_baseline);
    }

    public function test_the_review_tab_shows_results_and_sends_fixes_to_the_agent(): void
    {
        AgentCredential::factory()->ownedBy($this->editor)->create();
        Livewire::actingAs($this->editor)->test(ThreadChat::class, ['workspaceId' => $this->workspace->id])
            ->set('newTitle', 'Gates')->call('createThread')->assertHasNoErrors();
        $thread = Thread::query()->sole();

        $panel = Livewire::actingAs($this->editor)->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(__('workspaces.gates.never'))
            ->call('runGates')
            ->assertSee(__('workspaces.gates.result', ['passed' => 2, 'total' => 3]))
            ->assertSee(__('workspaces.gates.send_fix'))
            ->assertSee('LoginTest');

        $panel->call('sendGateFix', 'pest')->assertNotified(__('workspaces.gates.fix_sent'));
        $message = ThreadEvent::query()->where('thread_id', $thread->id)->where('type', ThreadEventType::UserMessage->value)->sole();
        $this->assertStringContainsString('# Quality-Gate-Fix: pest', $message->payload['text']);

        // Only failed gates can be sent.
        $panel->call('sendGateFix', 'pint')->assertStatus(404);
    }

    public function test_viewers_see_results_without_actions(): void
    {
        WorkspaceGateRun::factory()->create(['workspace_id' => $this->workspace->id, 'status' => GateRunStatus::Failed, 'gates' => ['pest' => 'fail']]);

        Livewire::actingAs($this->member(WorkspaceRole::Viewer, 'Vi'))->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('pest')
            ->assertDontSee(__('workspaces.gates.send_fix'))
            ->assertDontSee(__('workspaces.gates.run'));
    }

    public function test_the_header_action_runs_the_gates(): void
    {
        Livewire::actingAs($this->editor)->test(ViewWorkspace::class, ['record' => $this->workspace->id])
            ->callAction('gates')
            ->assertNotified(__('workspaces.gates.started'))
            ->assertDispatched('workspace-tab', tab: 'review');

        $this->assertSame(GateRunStatus::Failed, WorkspaceGateRun::query()->sole()->status);
        $this->assertSame('2/3', $this->service()->summary($this->workspace));
    }
}

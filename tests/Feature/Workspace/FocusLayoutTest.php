<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\ApprovalStatus;
use App\Enums\GateRunStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceSetupStatus;
use App\Filament\App\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Livewire\Workspace\ChangeTray;
use App\Livewire\Workspace\ContextPanel;
use App\Livewire\Workspace\TaskChat;
use App\Livewire\Workspace\TaskSwitcher;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskOverview;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceOnboarding;
use App\Support\GateRows;
use App\Support\TaskTimeline;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/** The "Fokus" workspace page: header switcher, chat, context sidebar. */
class FocusLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('app');
        Event::fake([TaskStream::class, UserWorkspaceSignal::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        Workspace::factory()->create();
        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Admin]);
    }

    private function task(string $title, SessionStatus $status = SessionStatus::Idle): Task
    {
        return Task::factory()->ownedBy($this->owner)->create(['workspace_id' => $this->workspace->id, 'title' => $title, 'session_status' => $status]);
    }

    public function test_the_page_wires_switcher_chat_and_context(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->assertSeeLivewire(TaskSwitcher::class)
            ->assertSeeLivewire(TaskChat::class)
            ->assertSeeLivewire(ContextPanel::class);
    }

    public function test_admins_see_the_open_setup_steps_until_the_setup_is_tested(): void
    {
        $this->workspace->forceFill(['setup_status' => WorkspaceSetupStatus::Untested])->save();
        $steps = app(WorkspaceOnboarding::class)->steps($this->workspace, $this->owner);
        $this->assertFalse(collect($steps)->firstWhere('key', 'setup')['done']);
        $this->assertTrue(app(WorkspaceOnboarding::class)->incomplete($this->workspace, $this->owner));

        Livewire::actingAs($this->owner)->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->assertSee(__('workspaces.onboarding.title'))
            ->assertSee(__('workspaces.onboarding.steps.setup'));

        // On the setup task itself its banner says what happens; the card would ask for the same step.
        $setup = Task::factory()->ownedBy($this->owner)->create(['workspace_id' => $this->workspace->id, 'kind' => TaskKind::Setup]);
        Livewire::actingAs($this->owner)->test(ViewWorkspace::class, ['record' => $this->workspace->getKey(), 'task' => $setup->slug])
            ->assertDontSee(__('workspaces.onboarding.title'));

        $member = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
        Livewire::actingAs($member)->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->assertDontSee(__('workspaces.onboarding.title'));
    }

    public function test_tasks_are_grouped_with_a_status_line(): void
    {
        $waiting = $this->task('Navbar', SessionStatus::WaitingInput);
        TaskApproval::factory()->create(['task_id' => $waiting->id, 'tool_name' => 'Bash', 'request' => ['input' => ['command' => 'npm run build']]]);
        $running = $this->task('API docs', SessionStatus::Running);
        TaskEvent::query()->create(['task_id' => $running->id, 'type' => TaskEventType::ToolCall, 'payload' => ['name' => 'Bash', 'input' => ['command' => 'vendor/bin/pest']]]);
        $done = $this->task('Login');
        TaskEvent::query()->create(['task_id' => $done->id, 'type' => TaskEventType::UserMessage, 'payload' => ['text' => 'go']]);
        foreach (['resources/views/login.blade.php', 'routes/web.php', 'routes/web.php'] as $path) {
            TaskEvent::query()->create(['task_id' => $done->id, 'type' => TaskEventType::ToolCall, 'payload' => ['name' => 'Edit', 'input' => ['file_path' => '/workspace/'.$path]]]);
        }
        $this->task('Old')->forceFill(['archived_at' => now()])->save();

        $overview = app(TaskOverview::class)->for($this->workspace);

        $this->assertSame(['Navbar'], array_map(fn (array $r): string => $r['task']->title, $overview['waiting']));
        $this->assertSame(__('workspaces.switcher.text.approval', ['what' => 'npm run build']), $overview['waiting'][0]['text']);
        $this->assertSame(__('workspaces.switcher.text.working_on', ['step' => 'pest']), $overview['running'][0]['text']);
        $this->assertSame(trans_choice('workspaces.switcher.text.done_files', 2, ['count' => 2]), $overview['ready'][0]['text']);
        $this->assertSame(1, $overview['archived']);
    }

    public function test_pull_requests_and_finished_tasks_have_their_own_groups(): void
    {
        $this->task('Open PR')->forceFill(['status' => TaskStatus::PrOpen, 'pr_number' => 12, 'pr_state' => 'draft'])->save();
        $this->task('Merged')->forceFill(['status' => TaskStatus::Done, 'done_at' => now()])->save();
        $this->task('Dropped')->forceFill(['status' => TaskStatus::Cancelled, 'done_at' => now()])->save();
        $this->task('Preparing')->forceFill(['status' => TaskStatus::Preparing])->save();

        $overview = app(TaskOverview::class)->for($this->workspace);

        $this->assertSame(['Open PR'], array_map(fn (array $r): string => $r['task']->title, $overview['pr_open']));
        $this->assertSame(__('workspaces.switcher.text.pr', ['number' => '12', 'state' => __('workspaces.pr_state.draft')]), $overview['pr_open'][0]['text']);
        $this->assertEqualsCanonicalizing(['Merged', 'Dropped'], array_map(fn (array $r): string => $r['task']->title, $overview['done']));
        $this->assertSame(__('workspaces.switcher.text.preparing'), $overview['running'][0]['text']);
    }

    public function test_the_switcher_shows_mine_and_invited_or_all_tasks(): void
    {
        $other = User::factory()->create(['name' => 'Bob']);
        $this->task('Mine');
        $invited = Task::factory()->ownedBy($other)->create(['workspace_id' => $this->workspace->id, 'title' => 'Invited', 'external_ref' => ['provider' => 'gitlab', 'key' => 'gitlab-42', 'number' => '42', 'title' => 'Login', 'url' => 'https://gitlab.com/x/-/issues/42']]);
        $invited->members()->create(['user_id' => $this->owner->id]);
        Task::factory()->ownedBy($other)->create(['workspace_id' => $this->workspace->id, 'title' => 'Bobs own']);

        $switcher = Livewire::actingAs($this->owner)->test(TaskSwitcher::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('Mine')->assertSee('Invited')->assertDontSee('Bobs own')
            ->assertSee('GitLab #42')
            ->assertSee(__('workspaces.switcher.owner', ['name' => 'Bob']), false);

        $switcher->set('mine', false)->assertSee('Bobs own');
    }

    public function test_the_hint_chip_shows_only_other_waiting_tasks_and_selects_them(): void
    {
        $current = $this->task('Login');
        $waiting = $this->task('Navbar', SessionStatus::WaitingInput);
        TaskApproval::factory()->create(['task_id' => $waiting->id]);

        Livewire::withQueryParams(['task' => $current->id])->actingAs($this->owner)
            ->test(TaskSwitcher::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(trans_choice('workspaces.switcher.waiting_chip', 1, ['count' => 1]))
            ->call('select', $waiting->id)
            ->assertDispatched('task-select', id: $waiting->id)
            ->assertDontSee(trans_choice('workspaces.switcher.waiting_chip', 1, ['count' => 1]));
    }

    public function test_the_chat_follows_the_switcher(): void
    {
        $first = $this->task('First');
        $second = $this->task('Second');

        Livewire::withQueryParams(['task' => $first->id])->actingAs($this->owner)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->dispatch('task-select', id: $second->id)
            ->assertSet('taskId', $second->id)
            ->assertDispatched('task-changed', id: $second->id)
            ->dispatch('task-new')
            ->assertSet('creating', true);
    }

    public function test_archiving_the_shown_task_moves_the_chat_on(): void
    {
        $shown = $this->task('Shown');
        $this->task('Next');

        Livewire::withQueryParams(['task' => $shown->id])->actingAs($this->owner)
            ->test(TaskSwitcher::class, ['workspaceId' => $this->workspace->id])
            ->call('archive', $shown->id)
            ->assertDispatched('task-archived', id: $shown->id);
        $this->assertNotNull($shown->fresh()->archived_at);
    }

    public function test_the_context_lists_gates_and_changed_files(): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id, 'branch' => 'argos/login-page', 'base_branch' => 'develop']);
        WorkspaceGateRun::factory()->create([
            'task_id' => $task->id,
            'status' => GateRunStatus::Failed,
            'gates' => ['pint' => 'pass', 'phpunit' => 'fail', 'phpstan' => 'skip', 'artisan' => 'pass', 'migrations' => 'pass'],
        ]);

        $rows = collect(GateRows::of(WorkspaceGateRun::query()->first()))->pluck('state', 'key')->all();
        $this->assertSame(['pint' => 'pass', 'pest' => 'fail', 'phpstan' => 'skip', 'artisan' => 'pass', 'migrations' => 'pass'], $rows);
        $this->assertSame('open', GateRows::of(null)[0]['state']);

        $files = ContextPanel::changedFiles([
            ['path' => 'app/a.php', 'status' => 'M', 'additions' => 2, 'deletions' => 1],
            ['path' => 'b.php', 'status' => 'A', 'additions' => 5, 'deletions' => 0],
            ['path' => 'c.php', 'status' => 'D', 'additions' => 0, 'deletions' => 3],
        ]);
        $this->assertSame('app', $files[0]['dir']);
        $this->assertSame(['M', 'A', 'D'], array_column($files, 'status'));

        Livewire::withoutLazyLoading()->actingAs($this->owner)
            ->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('Pest / PHPUnit')
            ->assertSee(__('workspaces.focus.gate_state.fail'))
            ->assertSee('argos/login-page')
            ->assertSee('→ develop');
    }

    public function test_the_timeline_groups_a_turn_with_steps_and_hides_allowed_tool_approvals(): void
    {
        $task = $this->task('Login');
        $make = fn (TaskEventType $type, array $payload): TaskEvent => TaskEvent::query()->create(['task_id' => $task->id, 'type' => $type, 'payload' => $payload]);
        $make(TaskEventType::UserMessage, ['text' => 'Build a login page']);
        $approval = TaskApproval::factory()->create(['task_id' => $task->id, 'status' => ApprovalStatus::Allowed]);
        $make(TaskEventType::ApprovalRequested, ['approval_id' => $approval->id, 'kind' => 'tool', 'tool_name' => 'Edit']);
        $make(TaskEventType::ToolCall, ['tool_use_id' => 't1', 'name' => 'Edit', 'input' => ['file_path' => '/workspace/routes/web.php', 'old_string' => 'a', 'new_string' => "a\nb"]]);
        $make(TaskEventType::ToolResult, ['tool_use_id' => 't1', 'output' => 'ok']);
        $make(TaskEventType::ToolCall, ['tool_use_id' => 't2', 'name' => 'Bash', 'input' => ['command' => 'php artisan route:list']]);
        $make(TaskEventType::ToolResult, ['tool_use_id' => 't2', 'output' => "2 routes\nmore"]);
        $make(TaskEventType::AssistantMessage, ['text' => 'Done.']);
        $make(TaskEventType::TurnCompleted, ['session_cost_usd' => 0.42, 'duration_ms' => 163000]);

        $events = TaskEvent::query()->where('task_id', $task->id)->orderBy('id')->get();
        $results = $events->where('type', TaskEventType::ToolResult)->keyBy(fn ($e) => $e->payload['tool_use_id']);
        $blocks = TaskTimeline::build($events, $results, collect([$approval->id => $approval]), false);

        $this->assertSame(['user', 'claude'], array_column($blocks, 'kind'));
        $turn = $blocks[1];
        $this->assertSame(163000, $turn['duration_ms']);
        $this->assertEqualsWithDelta(0.42, $turn['cost'], 0.0001);
        $this->assertSame(['steps', 'event'], array_column($turn['items'], 'kind'));
        [$edit, $bash] = $turn['items'][0]['rows'];
        $this->assertSame([__('workspaces.steps.edited'), 'routes/web.php', 2, 1], [$edit['verb'], $edit['target'], $edit['add'], $edit['del']]);
        $this->assertSame([__('workspaces.steps.ran'), '2 routes', 'ok'], [$bash['verb'], $bash['detail'], $bash['state']]);
        // Notices outside a Claude turn stand on their own.
        $this->assertNotContains('notice', array_column($blocks, 'kind'));
        // Every step carries the time Claude took it.
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', (string) $edit['at']);
    }

    public function test_always_allow_asks_the_daemon_to_remember(): void
    {
        $task = Task::factory()->open()->create(['workspace_id' => $this->workspace->id, 'session_status' => SessionStatus::WaitingInput]);
        $approval = TaskApproval::factory()->create(['task_id' => $task->id, 'tool_name' => 'Bash', 'request' => ['input' => ['command' => 'npm run build']]]);

        Livewire::withQueryParams(['task' => $task->id])->actingAs($this->owner)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->call('resolveApproval', $approval->id, 'always');

        $this->assertSame(ApprovalStatus::Allowed, $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->response['remember'] ?? false);
    }

    public function test_the_change_tray_summarises_the_gates(): void
    {
        $this->assertSame(__('workspaces.tray.gates_none'), ChangeTray::gateSummary(null));
        $run = WorkspaceGateRun::factory()->create(['workspace_id' => $this->workspace->id, 'status' => GateRunStatus::Failed, 'gates' => ['phpstan' => 'fail', 'pint' => 'pass']]);
        $this->assertSame(__('workspaces.tray.gates_failed', ['gates' => 'PHPStan']), ChangeTray::gateSummary($run));
        $run->update(['status' => GateRunStatus::Passed, 'gates' => ['phpstan' => 'pass']]);
        $this->assertSame(__('workspaces.tray.gates_green'), ChangeTray::gateSummary($run->fresh()));
    }

    public function test_notices_before_claude_works_stand_without_a_claude_head(): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $notice = fn (string $kind) => TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::Notice, 'payload' => ['kind' => $kind]]);
        $notice('prepare_step');
        $notice('prepared');
        TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::UserMessage, 'author_user_id' => $this->owner->id, 'payload' => ['text' => 'Hi']]);
        TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::AssistantMessage, 'payload' => ['text' => 'Hello']]);
        TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::TurnCompleted, 'payload' => []]);
        $notice('shipped');

        $events = TaskEvent::query()->where('task_id', $task->id)->orderBy('id')->get();
        $blocks = TaskTimeline::build($events, collect(), collect(), false);

        $this->assertSame(['notice', 'notice', 'user', 'claude', 'notice'], array_column($blocks, 'kind'));
    }
}

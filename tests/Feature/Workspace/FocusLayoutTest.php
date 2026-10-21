<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\ApprovalStatus;
use App\Enums\GateRunStatus;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Livewire\Workspace\ChangeTray;
use App\Livewire\Workspace\ContextPanel;
use App\Livewire\Workspace\ThreadChat;
use App\Livewire\Workspace\ThreadSwitcher;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\Workspace\ThreadOverview;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Support\ThreadTimeline;
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
        Filament::setCurrentPanel('workspaces');
        Event::fake([ThreadStream::class, UserWorkspaceSignal::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        RepoProfile::factory()->create();
        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
    }

    private function thread(string $title, ThreadStatus $status = ThreadStatus::Idle): Thread
    {
        return Thread::factory()->create(['workspace_id' => $this->workspace->id, 'title' => $title, 'status' => $status]);
    }

    public function test_the_page_wires_switcher_chat_and_context(): void
    {
        Livewire::actingAs($this->owner)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->assertSeeLivewire(ThreadSwitcher::class)
            ->assertSeeLivewire(ThreadChat::class)
            ->assertSeeLivewire(ContextPanel::class);
    }

    public function test_threads_are_grouped_with_a_status_line(): void
    {
        $waiting = $this->thread('Navbar', ThreadStatus::WaitingInput);
        ThreadApproval::factory()->create(['thread_id' => $waiting->id, 'tool_name' => 'Bash', 'request' => ['input' => ['command' => 'npm run build']]]);
        $running = $this->thread('API docs', ThreadStatus::Running);
        ThreadEvent::query()->create(['thread_id' => $running->id, 'type' => ThreadEventType::ToolCall, 'payload' => ['name' => 'Bash', 'input' => ['command' => 'vendor/bin/pest']]]);
        $done = $this->thread('Login');
        ThreadEvent::query()->create(['thread_id' => $done->id, 'type' => ThreadEventType::UserMessage, 'payload' => ['text' => 'go']]);
        foreach (['resources/views/login.blade.php', 'routes/web.php', 'routes/web.php'] as $path) {
            ThreadEvent::query()->create(['thread_id' => $done->id, 'type' => ThreadEventType::ToolCall, 'payload' => ['name' => 'Edit', 'input' => ['file_path' => '/workspace/'.$path]]]);
        }
        $this->thread('Old')->forceFill(['archived_at' => now()])->save();

        $overview = app(ThreadOverview::class)->for($this->workspace);

        $this->assertSame(['Navbar'], array_map(fn (array $r): string => $r['thread']->title, $overview['waiting']));
        $this->assertSame(__('workspaces.switcher.text.approval', ['what' => 'npm run build']), $overview['waiting'][0]['text']);
        $this->assertSame(__('workspaces.switcher.text.working_on', ['step' => 'pest']), $overview['running'][0]['text']);
        $this->assertSame(trans_choice('workspaces.switcher.text.done_files', 2, ['count' => 2]), $overview['done'][0]['text']);
        $this->assertSame(1, $overview['archived']);
    }

    public function test_the_hint_chip_shows_only_other_waiting_threads_and_selects_them(): void
    {
        $current = $this->thread('Login');
        $waiting = $this->thread('Navbar', ThreadStatus::WaitingInput);
        ThreadApproval::factory()->create(['thread_id' => $waiting->id]);

        Livewire::withQueryParams(['thread' => $current->id])->actingAs($this->owner)
            ->test(ThreadSwitcher::class, ['workspaceId' => $this->workspace->id])
            ->assertSee(trans_choice('workspaces.switcher.waiting_chip', 1, ['count' => 1]))
            ->call('select', $waiting->id)
            ->assertDispatched('thread-select', id: $waiting->id)
            ->assertDontSee(trans_choice('workspaces.switcher.waiting_chip', 1, ['count' => 1]));
    }

    public function test_the_chat_follows_the_switcher(): void
    {
        $first = $this->thread('First');
        $second = $this->thread('Second');

        Livewire::withQueryParams(['thread' => $first->id])->actingAs($this->owner)
            ->test(ThreadChat::class, ['workspaceId' => $this->workspace->id])
            ->dispatch('thread-select', id: $second->id)
            ->assertSet('threadId', $second->id)
            ->assertDispatched('thread-changed', id: $second->id)
            ->dispatch('thread-new')
            ->assertSet('creating', true);
    }

    public function test_archiving_the_shown_thread_moves_the_chat_on(): void
    {
        $shown = $this->thread('Shown');
        $this->thread('Next');

        Livewire::withQueryParams(['thread' => $shown->id])->actingAs($this->owner)
            ->test(ThreadSwitcher::class, ['workspaceId' => $this->workspace->id])
            ->call('archive', $shown->id)
            ->assertDispatched('thread-archived', id: $shown->id);
        $this->assertNotNull($shown->fresh()->archived_at);
    }

    public function test_the_context_lists_gates_and_changed_files(): void
    {
        WorkspaceGateRun::factory()->create([
            'workspace_id' => $this->workspace->id,
            'status' => GateRunStatus::Failed,
            'gates' => ['pint' => 'pass', 'phpunit' => 'fail', 'phpstan' => 'skip', 'artisan' => 'pass', 'migrations' => 'pass'],
        ]);

        $rows = collect(ContextPanel::gateRows(WorkspaceGateRun::query()->first()))->pluck('state', 'key')->all();
        $this->assertSame(['pint' => 'pass', 'pest' => 'fail', 'phpstan' => 'skip', 'artisan' => 'pass', 'migrations' => 'pass'], $rows);
        $this->assertSame('open', ContextPanel::gateRows(null)[0]['state']);

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
            ->assertSee($this->workspace->branch);
    }

    public function test_the_timeline_groups_a_turn_with_steps_and_hides_allowed_tool_approvals(): void
    {
        $thread = $this->thread('Login');
        $make = fn (ThreadEventType $type, array $payload): ThreadEvent => ThreadEvent::query()->create(['thread_id' => $thread->id, 'type' => $type, 'payload' => $payload]);
        $make(ThreadEventType::UserMessage, ['text' => 'Build a login page']);
        $approval = ThreadApproval::factory()->create(['thread_id' => $thread->id, 'status' => ApprovalStatus::Allowed]);
        $make(ThreadEventType::ApprovalRequested, ['approval_id' => $approval->id, 'kind' => 'tool', 'tool_name' => 'Edit']);
        $make(ThreadEventType::ToolCall, ['tool_use_id' => 't1', 'name' => 'Edit', 'input' => ['file_path' => '/workspace/routes/web.php', 'old_string' => 'a', 'new_string' => "a\nb"]]);
        $make(ThreadEventType::ToolResult, ['tool_use_id' => 't1', 'output' => 'ok']);
        $make(ThreadEventType::ToolCall, ['tool_use_id' => 't2', 'name' => 'Bash', 'input' => ['command' => 'php artisan route:list']]);
        $make(ThreadEventType::ToolResult, ['tool_use_id' => 't2', 'output' => "2 routes\nmore"]);
        $make(ThreadEventType::AssistantMessage, ['text' => 'Done.']);
        $make(ThreadEventType::TurnCompleted, ['session_cost_usd' => 0.42, 'duration_ms' => 163000]);

        $events = ThreadEvent::query()->where('thread_id', $thread->id)->orderBy('id')->get();
        $results = $events->where('type', ThreadEventType::ToolResult)->keyBy(fn ($e) => $e->payload['tool_use_id']);
        $blocks = ThreadTimeline::build($events, $results, collect([$approval->id => $approval]), false);

        $this->assertSame(['user', 'claude'], array_column($blocks, 'kind'));
        $turn = $blocks[1];
        $this->assertSame(163000, $turn['duration_ms']);
        $this->assertEqualsWithDelta(0.42, $turn['cost'], 0.0001);
        $this->assertSame(['steps', 'event'], array_column($turn['items'], 'kind'));
        [$edit, $bash] = $turn['items'][0]['rows'];
        $this->assertSame([__('workspaces.steps.edited'), 'routes/web.php', 2, 1], [$edit['verb'], $edit['target'], $edit['add'], $edit['del']]);
        $this->assertSame([__('workspaces.steps.ran'), '2 routes', 'ok'], [$bash['verb'], $bash['detail'], $bash['state']]);
    }

    public function test_always_allow_asks_the_daemon_to_remember(): void
    {
        $thread = Thread::factory()->open()->create(['workspace_id' => $this->workspace->id, 'status' => ThreadStatus::WaitingInput]);
        $approval = ThreadApproval::factory()->create(['thread_id' => $thread->id, 'tool_name' => 'Bash', 'request' => ['input' => ['command' => 'npm run build']]]);

        Livewire::withQueryParams(['thread' => $thread->id])->actingAs($this->owner)
            ->test(ThreadChat::class, ['workspaceId' => $this->workspace->id])
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
}

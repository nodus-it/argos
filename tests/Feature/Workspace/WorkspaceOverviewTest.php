<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Profile;
use App\Filament\Admin\Widgets\MyWorkspacesWidget;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Livewire\Workspace\ThreadChat;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\ThreadEventIngestor;
use App\Services\Workspace\ThreadReadService;
use App\Services\Workspace\WorkspaceSummaryService;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Thread $thread;

    private User $owner;

    private User $editor;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Event::fake([ThreadStream::class, UserWorkspaceSignal::class]);
        // Filament's database notification is queued; assert what was sent.
        Notification::fake();
        RepoProfile::factory()->create();
        $this->workspace = Workspace::factory()->create();
        $this->thread = Thread::factory()->open()->create(['workspace_id' => $this->workspace->id, 'title' => 'Login']);
        $this->owner = $this->member(WorkspaceRole::Owner, 'Ada');
        $this->editor = $this->member(WorkspaceRole::Editor, 'Bob');
        $this->viewer = $this->member(WorkspaceRole::Viewer, 'Vi');
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function ingest(array $events): void
    {
        $seq = $this->thread->fresh()->last_seq;
        $batch = [];
        foreach ($events as [$type, $data]) {
            $batch[] = ['thread_id' => $this->thread->id, 'seq' => ++$seq, 'type' => $type, 'data' => $data];
        }
        app(ThreadEventIngestor::class)->ingest($this->workspace, $batch);
    }

    public function test_unread_counts_skip_own_messages_and_tool_noise(): void
    {
        ThreadEvent::query()->create(['thread_id' => $this->thread->id, 'type' => ThreadEventType::UserMessage, 'author_user_id' => $this->owner->id, 'payload' => ['text' => 'mine']]);
        ThreadEvent::query()->create(['thread_id' => $this->thread->id, 'type' => ThreadEventType::AssistantMessage, 'payload' => ['text' => 'answer']]);
        ThreadEvent::query()->create(['thread_id' => $this->thread->id, 'type' => ThreadEventType::ToolResult, 'payload' => ['output' => 'x']]);
        $reads = app(ThreadReadService::class);

        $this->assertSame([$this->thread->id => 1], $reads->unreadCounts(collect([$this->thread]), $this->owner));
        $this->assertSame([$this->thread->id => 2], $reads->unreadCounts(collect([$this->thread]), $this->editor));

        $reads->markRead($this->thread, $this->editor);
        $this->assertSame([$this->thread->id => 0], $reads->unreadCounts(collect([$this->thread]), $this->editor));
    }

    public function test_approvals_notify_operators_in_the_bell_and_live(): void
    {
        $this->ingest([['approval.requested', ['approval_id' => '01JAPPROVAL000000000000000', 'kind' => 'tool', 'tool_name' => 'Edit']]]);

        Notification::assertSentTo($this->owner, DatabaseNotification::class);
        Notification::assertSentTo($this->editor, DatabaseNotification::class);
        Notification::assertNotSentTo($this->viewer, DatabaseNotification::class);
        Event::assertDispatched(UserWorkspaceSignal::class, fn (UserWorkspaceSignal $s): bool => $s->userId === $this->editor->id && $s->signal['kind'] === 'approval');
        Event::assertNotDispatched(UserWorkspaceSignal::class, fn (UserWorkspaceSignal $s): bool => $s->userId === $this->viewer->id && $s->signal['kind'] === 'approval');
    }

    public function test_preferences_switch_kinds_off(): void
    {
        $this->editor->forceFill(['notification_prefs' => ['approval' => false]])->save();

        $this->ingest([['approval.requested', ['approval_id' => '01JAPPROVAL000000000000001', 'kind' => 'tool', 'tool_name' => 'Edit']]]);

        Notification::assertNotSentTo($this->editor, DatabaseNotification::class);
        Notification::assertSentTo($this->owner, DatabaseNotification::class);
    }

    public function test_only_long_turns_signal_and_never_in_the_bell(): void
    {
        $this->ingest([['turn.completed', ['duration_ms' => 5_000]]]);
        Event::assertNotDispatched(UserWorkspaceSignal::class, fn (UserWorkspaceSignal $s): bool => $s->signal['kind'] === 'turn');

        $this->ingest([['turn.completed', ['duration_ms' => 45_000]]]);
        Event::assertDispatched(UserWorkspaceSignal::class, fn (UserWorkspaceSignal $s): bool => $s->signal['kind'] === 'turn');
        Notification::assertNothingSent();
    }

    public function test_session_end_is_not_an_error_notification_but_status_refreshes_overviews(): void
    {
        $this->ingest([['error', ['code' => 'session_ended', 'message' => 'ended']], ['status', ['state' => 'closed']]]);

        Notification::assertNothingSent();
        Event::assertDispatched(UserWorkspaceSignal::class, fn (UserWorkspaceSignal $s): bool => $s->signal['kind'] === 'summary' && $s->userId === $this->viewer->id);
    }

    public function test_summary_waits_for_operators_only(): void
    {
        $this->thread->forceFill(['status' => ThreadStatus::WaitingInput])->save();
        ThreadApproval::factory()->create(['thread_id' => $this->thread->id]);
        $summaries = app(WorkspaceSummaryService::class);

        $this->assertTrue($summaries->for($this->workspace, $this->editor)['waiting_for_me']);
        $this->assertFalse($summaries->for($this->workspace, $this->viewer)['waiting_for_me']);
        $this->assertSame(1, $summaries->for($this->workspace, $this->editor)['waiting']);
    }

    public function test_overview_lists_waiting_workspaces_first(): void
    {
        $quiet = Workspace::factory()->create(['last_activity_at' => now()]);
        WorkspaceMember::query()->create(['workspace_id' => $quiet->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Editor]);
        $this->workspace->forceFill(['last_activity_at' => now()->subDay()])->save();
        ThreadApproval::factory()->create(['thread_id' => $this->thread->id]);

        $rows = app(WorkspaceSummaryService::class)->overviewFor($this->editor);

        $this->assertSame([$this->workspace->id, $quiet->id], $rows->map(fn ($r) => $r['workspace']->id)->all());
    }

    public function test_sidebar_shows_unread_threads_and_archiving_hides_them(): void
    {
        $other = Thread::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Checkout']);
        ThreadEvent::query()->create(['thread_id' => $other->id, 'type' => ThreadEventType::AssistantMessage, 'payload' => ['text' => 'news']]);

        $chat = Livewire::withQueryParams(['thread' => $this->thread->id])
            ->actingAs($this->editor)
            ->test(ThreadChat::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('Checkout')
            ->assertSeeHtml('<span class="ws-unread">1</span>');

        $chat->call('archive', $other->id)->assertDontSee('Checkout');
        $this->assertNotNull($other->fresh()->archived_at);

        $chat->set('showArchived', true)->assertSee('Checkout')->call('unarchive', $other->id);
        $this->assertNull($other->fresh()->archived_at);

        $chat->call('startRename', $this->thread->id)->set('renameTitle', 'Login v2')->call('rename');
        $this->assertSame('Login v2', $this->thread->fresh()->title);
    }

    public function test_dashboard_shows_my_workspaces_widget_for_members(): void
    {
        $this->actingAs($this->editor);
        Livewire::test(Dashboard::class)->assertSeeLivewire(MyWorkspacesWidget::class);
        Livewire::test(MyWorkspacesWidget::class)->assertSee($this->workspace->name);

        $this->actingAs(User::factory()->create());
        $this->assertFalse(MyWorkspacesWidget::canView());
    }

    public function test_profile_saves_notification_preferences(): void
    {
        $this->actingAs($this->editor);

        Livewire::test(Profile::class)
            ->fillForm(['notification_prefs.turn' => false, 'notification_prefs.approval' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->editor->fresh()->wantsNotification('turn'));
        $this->assertTrue($this->editor->fresh()->wantsNotification('approval'));
    }

    public function test_the_list_filters_workspaces_waiting_for_me(): void
    {
        $quiet = Workspace::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $quiet->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Editor]);
        ThreadApproval::factory()->create(['thread_id' => $this->thread->id]);

        Livewire::actingAs($this->editor)->test(ListWorkspaces::class)
            ->filterTable('waiting_for_me')
            ->assertCanSeeTableRecords([$this->workspace])
            ->assertCanNotSeeTableRecords([$quiet]);
    }
}

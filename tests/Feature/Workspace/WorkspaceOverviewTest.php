<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Broadcasting\UserWorkspaceSignal;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Profile;
use App\Filament\Admin\Widgets\MyWorkspacesWidget;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Livewire\Workspace\TaskSwitcher;
use App\Models\Demo;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\TaskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\TaskReadService;
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

    private Task $task;

    private User $owner;

    private User $editor;

    private User $viewer;

    private User $collaborator;

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('workspaces');
        Event::fake([TaskStream::class, UserWorkspaceSignal::class]);
        // Filament's database notification is queued; assert what was sent.
        Notification::fake();
        Workspace::factory()->create();
        $this->workspace = Workspace::factory()->create();
        $this->owner = $this->member(WorkspaceRole::Admin, 'Ada');
        $this->editor = $this->member(WorkspaceRole::Member, 'Bob');
        $this->viewer = $this->member(WorkspaceRole::Viewer, 'Vi');
        $this->collaborator = $this->member(WorkspaceRole::Member, 'Cy');
        // Bob owns the task, Cy collaborates on it.
        $this->task = Task::factory()->open()->ownedBy($this->editor)->create(['workspace_id' => $this->workspace->id, 'title' => 'Login']);
        TaskMember::query()->create(['task_id' => $this->task->id, 'user_id' => $this->collaborator->id, 'invited_by' => $this->editor->id]);
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
        $seq = $this->task->fresh()->last_seq;
        $batch = [];
        foreach ($events as [$type, $data]) {
            $batch[] = ['task_id' => $this->task->id, 'seq' => ++$seq, 'type' => $type, 'data' => $data];
        }
        app(TaskEventIngestor::class)->ingest($this->workspace, $batch);
    }

    public function test_unread_counts_skip_own_messages_and_tool_noise(): void
    {
        TaskEvent::query()->create(['task_id' => $this->task->id, 'type' => TaskEventType::UserMessage, 'author_user_id' => $this->owner->id, 'payload' => ['text' => 'mine']]);
        TaskEvent::query()->create(['task_id' => $this->task->id, 'type' => TaskEventType::AssistantMessage, 'payload' => ['text' => 'answer']]);
        TaskEvent::query()->create(['task_id' => $this->task->id, 'type' => TaskEventType::ToolResult, 'payload' => ['output' => 'x']]);
        $reads = app(TaskReadService::class);

        $this->assertSame([$this->task->id => 1], $reads->unreadCounts(collect([$this->task]), $this->owner));
        $this->assertSame([$this->task->id => 2], $reads->unreadCounts(collect([$this->task]), $this->editor));

        $reads->markRead($this->task, $this->editor);
        $this->assertSame([$this->task->id => 0], $reads->unreadCounts(collect([$this->task]), $this->editor));
    }

    public function test_approvals_notify_the_people_of_the_task_in_the_bell_and_live(): void
    {
        $this->ingest([['approval.requested', ['approval_id' => '01JAPPROVAL000000000000000', 'kind' => 'tool', 'tool_name' => 'Edit']]]);

        Notification::assertSentTo($this->editor, DatabaseNotification::class);
        Notification::assertSentTo($this->collaborator, DatabaseNotification::class);
        // Workspace admins and viewers who are not part of the task are not bothered.
        Notification::assertNotSentTo($this->owner, DatabaseNotification::class);
        Notification::assertNotSentTo($this->viewer, DatabaseNotification::class);
        Event::assertDispatched(UserWorkspaceSignal::class, fn (UserWorkspaceSignal $s): bool => $s->userId === $this->editor->id && $s->signal['kind'] === 'approval');
        Event::assertNotDispatched(UserWorkspaceSignal::class, fn (UserWorkspaceSignal $s): bool => $s->userId === $this->viewer->id && $s->signal['kind'] === 'approval');
    }

    public function test_preferences_switch_kinds_off(): void
    {
        $this->editor->forceFill(['notification_prefs' => ['approval' => false]])->save();

        $this->ingest([['approval.requested', ['approval_id' => '01JAPPROVAL000000000000001', 'kind' => 'tool', 'tool_name' => 'Edit']]]);

        Notification::assertNotSentTo($this->editor, DatabaseNotification::class);
        Notification::assertSentTo($this->collaborator, DatabaseNotification::class);
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

    public function test_summary_waits_for_the_people_of_the_task_only(): void
    {
        $this->task->forceFill(['session_status' => SessionStatus::WaitingInput])->save();
        TaskApproval::factory()->create(['task_id' => $this->task->id]);
        $summaries = app(WorkspaceSummaryService::class);

        $this->assertTrue($summaries->for($this->workspace, $this->editor)['waiting_for_me']);
        $this->assertTrue($summaries->for($this->workspace, $this->collaborator)['waiting_for_me']);
        $this->assertFalse($summaries->for($this->workspace, $this->owner)['waiting_for_me']);
        $this->assertFalse($summaries->for($this->workspace, $this->viewer)['waiting_for_me']);
        $this->assertSame(1, $summaries->for($this->workspace, $this->editor)['waiting']);
    }

    public function test_summary_counts_tasks_open_pull_requests_and_live_previews(): void
    {
        $this->task->forceFill(['session_status' => SessionStatus::Running, 'status' => TaskStatus::PrOpen])->save();
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'status' => TaskStatus::PrOpen]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'archived_at' => now()]);
        Demo::factory()->live()->create(['task_id' => $this->task->id]);
        Demo::factory()->failed()->create(['task_id' => $this->task->id]);

        $summary = app(WorkspaceSummaryService::class)->for($this->workspace, $this->viewer);

        $this->assertSame(1, $summary['working']);
        $this->assertSame(2, $summary['tasks']);
        $this->assertSame(2, $summary['pr_open']);
        $this->assertSame(1, $summary['previews']);
    }

    public function test_overview_lists_waiting_workspaces_first(): void
    {
        $quiet = Workspace::factory()->create(['last_activity_at' => now()]);
        WorkspaceMember::query()->create(['workspace_id' => $quiet->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Member]);
        $this->workspace->forceFill(['last_activity_at' => now()->subDay()])->save();
        TaskApproval::factory()->create(['task_id' => $this->task->id]);

        $rows = app(WorkspaceSummaryService::class)->overviewFor($this->editor);

        $this->assertSame([$this->workspace->id, $quiet->id], $rows->map(fn ($r) => $r['workspace']->id)->all());
    }

    public function test_switcher_shows_unread_tasks_and_archiving_hides_them(): void
    {
        $other = Task::factory()->ownedBy($this->editor)->create(['workspace_id' => $this->workspace->id, 'title' => 'Checkout']);
        TaskEvent::query()->create(['task_id' => $other->id, 'type' => TaskEventType::AssistantMessage, 'payload' => ['text' => 'news']]);

        $chat = Livewire::withQueryParams(['task' => $this->task->id])
            ->actingAs($this->editor)
            ->test(TaskSwitcher::class, ['workspaceId' => $this->workspace->id])
            ->assertSee('Checkout')
            ->assertSeeHtml('class="wf-unread"');

        $chat->call('archive', $other->id)->assertDontSee('Checkout');
        $this->assertNotNull($other->fresh()->archived_at);

        $chat->set('showArchived', true)->assertSee('Checkout')->call('unarchive', $other->id);
        $this->assertNull($other->fresh()->archived_at);

        $chat->call('startRename', $this->task->id)->set('renameTitle', 'Login v2')->call('rename');
        $this->assertSame('Login v2', $this->task->fresh()->title);
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
        WorkspaceMember::query()->create(['workspace_id' => $quiet->id, 'user_id' => $this->editor->id, 'role' => WorkspaceRole::Member]);
        TaskApproval::factory()->create(['task_id' => $this->task->id]);

        Livewire::actingAs($this->editor)->test(ListWorkspaces::class)
            ->filterTable('waiting_for_me')
            ->assertCanSeeTableRecords([$this->workspace])
            ->assertCanNotSeeTableRecords([$quiet]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\ApprovalStatus;
use App\Enums\PermissionMode;
use App\Enums\TaskEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class TaskChatTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // New tasks are prepared inline by the fake daemon (worktree, setup).
        config(['queue.default' => 'sync']);
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = $this->member(WorkspaceRole::Admin, 'Ada');
        AgentCredential::factory()->ownedBy($this->owner)->create(['name' => 'Ada Max']);
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function chat(User $as)
    {
        return Livewire::actingAs($as)->test(TaskChat::class, ['workspaceId' => $this->workspace->id]);
    }

    private function invite(Task $task, User $user): void
    {
        $task->members()->create(['user_id' => $user->id, 'invited_by' => $task->owner_user_id]);
    }

    private function createTask(): Task
    {
        $this->chat($this->owner)->set('newTitle', 'Login')->call('createTask')->assertHasNoErrors();

        return Task::query()->where('title', 'Login')->firstOrFail();
    }

    public function test_without_a_task_the_create_form_is_shown(): void
    {
        $this->chat($this->owner)
            ->assertSet('creating', true)
            ->assertSee(__('workspaces.task.create'))
            ->assertSee('Ada Max');
    }

    public function test_users_without_an_agent_are_sent_to_add_one(): void
    {
        $editor = $this->member(WorkspaceRole::Member, 'Bob');

        $this->chat($editor)->assertSee(__('workspaces.task.no_agent'));
    }

    public function test_send_streams_through_the_ingest_path_and_renders_the_answer(): void
    {
        $task = $this->createTask();

        $this->chat($this->owner)
            ->set('taskId', $task->id)
            ->set('draft', 'Hi Claude')
            ->call('send')
            ->assertSet('draft', '')
            ->assertSee('Hi Claude')
            ->assertSee('Echo: [Ada]: Hi Claude', false)
            ->assertSeeHtml('class="ag-claude-badge"');

        $this->assertTrue($task->fresh()->session_open);
    }

    public function test_a_message_the_ended_session_never_took_can_be_sent_again(): void
    {
        $task = $this->createTask();
        $this->chat($this->owner)->set('taskId', $task->id)->set('draft', 'Hi Claude')->call('send');
        $message = TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::UserMessage->value)->latest('id')->firstOrFail();
        $message->forceFill(['payload' => array_diff_key($message->payload, ['accepted_at' => true])])->save();

        // The daemon reports it dropped when the session ended.
        app(TaskEventIngestor::class)->ingest($this->workspace, [
            ['task_id' => $task->id, 'seq' => $task->fresh()->last_seq + 1, 'type' => 'user.dropped', 'data' => ['client_msg_ids' => [(string) $message->id]]],
        ]);
        $this->assertTrue($message->fresh()->payload['undelivered']);

        $this->chat($this->owner)->set('taskId', $task->id)
            ->assertSee(__('workspaces.events.undelivered'))
            ->assertSeeHtml('wire:click="resend('.$message->id.')"')
            ->call('resend', $message->id)
            ->assertDontSee(__('workspaces.events.undelivered'));

        $this->assertArrayNotHasKey('undelivered', $message->fresh()->payload);
        $this->assertArrayHasKey('accepted_at', $message->fresh()->payload);
    }

    public function test_pair_programming_messages_carry_their_author(): void
    {
        $task = $this->createTask();
        $bob = $this->member(WorkspaceRole::Member, 'Bob');
        $this->invite($task, $bob);

        $this->chat($bob)->set('taskId', $task->id)->set('draft', 'from bob')->call('send');

        $this->chat($this->owner)->set('taskId', $task->id)
            ->assertSee('Bob')
            ->assertSee('Echo: [Bob]: from bob', false)
            // The newest message a person wrote stands out; the working bar is ready above the composer.
            ->assertSeeHtml('is-latest')
            ->assertSeeHtml('ag-working-bar');
    }

    public function test_claude_is_told_not_to_write_concepts_unasked(): void
    {
        $task = $this->createTask();

        $this->assertStringContainsString('only when someone asks for one', app(TaskService::class)->systemPrompt($task));
    }

    public function test_claude_answers_in_the_users_language_and_says_du_in_german(): void
    {
        $prompt = app(TaskService::class)->systemPrompt($this->createTask());

        $this->assertStringContainsString('language the humans write in', $prompt);
        $this->assertStringContainsString('informally with "du"', $prompt);
    }

    public function test_approvals_are_decided_in_the_chat(): void
    {
        $task = $this->createTask();
        $chat = $this->chat($this->owner)->set('taskId', $task->id)->set('draft', 'please edit the readme')->call('send');

        $approval = TaskApproval::query()->where('task_id', $task->id)->firstOrFail();
        $chat->assertSee(__('workspaces.approval.allow'))->assertSee('README.md');

        $chat->call('resolveApproval', $approval->id, 'allow')
            ->assertSee('Done — README.md is updated.');

        $this->assertSame(ApprovalStatus::Allowed, $approval->fresh()->status);
        $this->assertSame($this->owner->id, $approval->fresh()->resolved_by);
    }

    public function test_viewers_read_but_cannot_write_or_decide(): void
    {
        $task = $this->createTask();
        $this->chat($this->owner)->set('taskId', $task->id)->set('draft', 'edit please')->call('send');
        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');

        $chat = $this->chat($viewer)->set('taskId', $task->id)
            ->assertSee(__('workspaces.task.viewer_hint'))
            ->assertDontSee(__('workspaces.task.send'))
            ->assertDontSee(__('workspaces.approval.allow'));

        $approval = TaskApproval::query()->firstOrFail();
        $chat->call('resolveApproval', $approval->id, 'allow');
        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_members_read_other_peoples_tasks_but_write_only_when_invited(): void
    {
        $task = $this->createTask();
        $this->assertTrue($task->isOwnedBy($this->owner));
        $bob = $this->member(WorkspaceRole::Member, 'Bob');

        $this->chat($bob)->set('taskId', $task->id)
            ->assertSee(__('workspaces.task.viewer_hint'))
            ->assertDontSee(__('workspaces.task.send'))
            ->set('draft', 'sneaky')->call('send');
        $this->assertFalse($task->fresh()->session_open);

        $this->invite($task, $bob);
        $this->chat($bob)->set('taskId', $task->id)->assertSee(__('workspaces.task.send'));
    }

    public function test_mode_switch_and_bypass_visibility(): void
    {
        $task = $this->createTask();
        $editor = $this->member(WorkspaceRole::Member, 'Bob');
        $this->invite($task, $editor);

        $this->chat($editor)->set('taskId', $task->id)
            ->assertDontSee(PermissionMode::BypassPermissions->label())
            ->call('setMode', 'acceptEdits');
        $this->assertSame(PermissionMode::AcceptEdits, $task->fresh()->permission_mode);

        $this->chat($editor)->set('taskId', $task->id)->call('setMode', 'bypassPermissions')->assertNotified();
        $this->assertSame(PermissionMode::AcceptEdits, $task->fresh()->permission_mode);

        $this->chat($this->owner)->set('taskId', $task->id)->assertSee(PermissionMode::BypassPermissions->label());
    }

    public function test_tasks_of_other_workspaces_cannot_be_selected(): void
    {
        $foreign = Task::factory()->create();

        $this->chat($this->owner)->call('selectTask', $foreign->id)->assertSet('taskId', null);
    }

    public function test_non_members_cannot_mount_the_chat(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->assertForbidden();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\ApprovalStatus;
use App\Enums\PermissionMode;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\ThreadChat;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class ThreadChatTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ThreadStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = $this->member(WorkspaceRole::Owner, 'Ada');
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
        return Livewire::actingAs($as)->test(ThreadChat::class, ['workspaceId' => $this->workspace->id]);
    }

    private function createThread(): Thread
    {
        $this->chat($this->owner)->set('newTitle', 'Login')->call('createThread')->assertHasNoErrors();

        return Thread::query()->where('title', 'Login')->firstOrFail();
    }

    public function test_without_a_thread_the_create_form_is_shown(): void
    {
        $this->chat($this->owner)
            ->assertSet('creating', true)
            ->assertSee(__('workspaces.thread.create'))
            ->assertSee('Ada Max');
    }

    public function test_users_without_an_agent_are_sent_to_add_one(): void
    {
        $editor = $this->member(WorkspaceRole::Editor, 'Bob');

        $this->chat($editor)->assertSee(__('workspaces.thread.no_agent'));
    }

    public function test_send_streams_through_the_ingest_path_and_renders_the_answer(): void
    {
        $thread = $this->createThread();

        $this->chat($this->owner)
            ->set('threadId', $thread->id)
            ->set('draft', 'Hi Claude')
            ->call('send')
            ->assertSet('draft', '')
            ->assertSee('Hi Claude')
            ->assertSee('Echo: [Ada]: Hi Claude', false)
            ->assertSee(__('workspaces.events.turn_done'));

        $this->assertTrue($thread->fresh()->session_open);
    }

    public function test_pair_programming_messages_carry_their_author(): void
    {
        $thread = $this->createThread();
        $bob = $this->member(WorkspaceRole::Editor, 'Bob');

        $this->chat($bob)->set('threadId', $thread->id)->set('draft', 'from bob')->call('send');

        $this->chat($this->owner)->set('threadId', $thread->id)
            ->assertSee('Bob')
            ->assertSee('Echo: [Bob]: from bob', false);
    }

    public function test_approvals_are_decided_in_the_chat(): void
    {
        $thread = $this->createThread();
        $chat = $this->chat($this->owner)->set('threadId', $thread->id)->set('draft', 'please edit the readme')->call('send');

        $approval = ThreadApproval::query()->where('thread_id', $thread->id)->firstOrFail();
        $chat->assertSee(__('workspaces.approval.allow'))->assertSee('README.md');

        $chat->call('resolveApproval', $approval->id, 'allow')
            ->assertSee('Done — README.md is updated.');

        $this->assertSame(ApprovalStatus::Allowed, $approval->fresh()->status);
        $this->assertSame($this->owner->id, $approval->fresh()->resolved_by);
    }

    public function test_viewers_read_but_cannot_write_or_decide(): void
    {
        $thread = $this->createThread();
        $this->chat($this->owner)->set('threadId', $thread->id)->set('draft', 'edit please')->call('send');
        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');

        $chat = $this->chat($viewer)->set('threadId', $thread->id)
            ->assertSee(__('workspaces.thread.viewer_hint'))
            ->assertDontSee(__('workspaces.thread.send'))
            ->assertDontSee(__('workspaces.approval.allow'));

        $approval = ThreadApproval::query()->firstOrFail();
        $chat->call('resolveApproval', $approval->id, 'allow');
        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_mode_switch_and_bypass_visibility(): void
    {
        $thread = $this->createThread();
        $editor = $this->member(WorkspaceRole::Editor, 'Bob');

        $this->chat($editor)->set('threadId', $thread->id)
            ->assertDontSee(PermissionMode::BypassPermissions->label())
            ->call('setMode', 'acceptEdits');
        $this->assertSame(PermissionMode::AcceptEdits, $thread->fresh()->permission_mode);

        $this->chat($editor)->set('threadId', $thread->id)->call('setMode', 'bypassPermissions')->assertNotified();
        $this->assertSame(PermissionMode::AcceptEdits, $thread->fresh()->permission_mode);

        $this->chat($this->owner)->set('threadId', $thread->id)->assertSee(PermissionMode::BypassPermissions->label());
    }

    public function test_threads_of_other_workspaces_cannot_be_selected(): void
    {
        $foreign = Thread::factory()->create();

        $this->chat($this->owner)->call('selectThread', $foreign->id)->assertSet('threadId', null);
    }

    public function test_non_members_cannot_mount_the_chat(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(ThreadChat::class, ['workspaceId' => $this->workspace->id])
            ->assertForbidden();
    }
}

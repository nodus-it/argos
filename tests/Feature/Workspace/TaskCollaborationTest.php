<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskMembershipService;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\TaskTicketService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Owners invite collaborators, hand tasks over, cancel, delete and follow up. */
class TaskCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $ada;

    private User $bob;

    private AgentCredential $adaAgent;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->ada = $this->member('Ada', WorkspaceRole::Member);
        $this->bob = $this->member('Bob', WorkspaceRole::Member);
        $this->adaAgent = AgentCredential::factory()->ownedBy($this->ada)->create(['credentials' => ['token' => 'oat-ada'], 'max_concurrent_sessions' => 3]);
    }

    private function member(string $name, WorkspaceRole $role): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function service(): TaskService
    {
        return app(TaskService::class);
    }

    private function task(): Task
    {
        return $this->service()->create($this->workspace, $this->ada, $this->adaAgent, 'Login')->fresh();
    }

    /** @return list<string> */
    private function noticeKinds(Task $task): array
    {
        return TaskEvent::query()->where('task_id', $task->id)->where('type', TaskEventType::Notice->value)->orderBy('id')->get()->pluck('payload.kind')->all();
    }

    public function test_the_owner_invites_a_member_who_can_then_write(): void
    {
        $task = $this->task();
        $this->assertFalse($this->bob->can('prompt', $task));

        app(TaskMembershipService::class)->invite($task, $this->ada, $this->bob);

        $this->assertTrue($this->bob->fresh()->can('prompt', $task->fresh()));
        $this->assertContains('invited', $this->noticeKinds($task));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->bob->id]);

        // Again is a no-op; removing takes the right back.
        app(TaskMembershipService::class)->invite($task, $this->ada, $this->bob);
        $this->assertSame(1, $task->members()->count());
        app(TaskMembershipService::class)->uninvite($task, $this->ada, $this->bob);
        $this->assertFalse($this->bob->can('prompt', $task->fresh()));
    }

    public function test_only_the_owner_invites_and_only_members(): void
    {
        $task = $this->task();
        $stranger = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        try {
            app(TaskMembershipService::class)->invite($task, $this->ada, $stranger);
            $this->fail('expected a refusal');
        } catch (WorkspaceException) {
            // not a member
        }
        app(TaskMembershipService::class)->invite($task, $this->bob, $this->bob);
    }

    public function test_inviting_sits_behind_the_collaboration_gate(): void
    {
        config(['argos.workspaces.collaboration' => false]);
        $task = $this->task();

        $this->expectException(WorkspaceException::class);
        app(TaskMembershipService::class)->invite($task, $this->ada, $this->bob);
    }

    public function test_handing_over_moves_the_task_to_the_new_owners_subscription(): void
    {
        $bobAgent = AgentCredential::factory()->ownedBy($this->bob)->create(['credentials' => ['token' => 'oat-bob']]);
        $task = $this->task();
        $this->service()->send($task, $this->ada, 'start');
        $this->assertTrue($task->fresh()->session_open);

        app(TaskMembershipService::class)->transfer($task->fresh(), $this->ada, $this->bob);

        $task->refresh();
        $this->assertSame($this->bob->id, $task->owner_user_id);
        $this->assertSame($bobAgent->id, $task->agent_credential_id);
        $this->assertFalse($task->session_open, 'the old subscription\'s session ends');
        // Ada stays on as collaborator, Bob is not a collaborator of his own task.
        $this->assertTrue($task->members()->where('user_id', $this->ada->id)->exists());
        $this->assertFalse($task->members()->where('user_id', $this->bob->id)->exists());
        $this->assertTrue($this->ada->can('prompt', $task));
        $this->assertFalse($this->ada->can('transfer', $task));
        $this->assertContains('transferred', $this->noticeKinds($task));
    }

    public function test_handing_over_needs_a_member_with_a_subscription(): void
    {
        $task = $this->task();
        $viewer = $this->member('Vera', WorkspaceRole::Viewer);

        foreach ([$viewer, $this->bob] as $newOwner) {
            try {
                app(TaskMembershipService::class)->transfer($task, $this->ada, $newOwner);
                $this->fail('expected a refusal for '.$newOwner->name);
            } catch (WorkspaceException) {
                $this->assertSame($this->ada->id, $task->fresh()->owner_user_id);
            }
        }
    }

    public function test_cancelling_finishes_the_task_and_blocks_writing(): void
    {
        $task = $this->task();
        $this->service()->send($task, $this->ada, 'start');

        $this->service()->cancel($task->fresh(), $this->ada);

        $task->refresh();
        $this->assertSame(TaskStatus::Cancelled, $task->status);
        $this->assertNotNull($task->done_at);
        $this->assertFalse($task->session_open);
        $this->assertContains('cancelled', $this->noticeKinds($task));

        $this->expectException(WorkspaceException::class);
        $this->service()->send($task, $this->ada, 'more');
    }

    public function test_deleting_removes_the_task_with_its_working_copy(): void
    {
        $task = $this->task();

        $this->service()->discard($task, $this->ada);

        $this->assertNull(Task::query()->find($task->id));
        $this->assertSame([$task->slug], FakeWorkspaceDaemon::$removed);
    }

    public function test_a_follow_up_continues_with_the_same_ticket_on_a_new_branch(): void
    {
        $task = $this->task();
        $task->forceFill([
            'status' => TaskStatus::Done,
            'pr_url' => 'https://github.com/acme/widget/pull/7',
            'pr_state' => 'merged',
            'external_ref' => ['provider' => 'github', 'key' => 'github-42', 'number' => '42', 'title' => 'Login', 'url' => 'https://x/42'],
        ])->save();
        TaskEvent::query()->create(['task_id' => $task->id, 'type' => TaskEventType::AssistantMessage, 'payload' => ['text' => 'Login works now.']]);

        $follow = $this->service()->followUp($task, $this->ada, $this->adaAgent)->fresh();

        $this->assertSame($task->id, $follow->parent_task_id);
        $this->assertSame('42', $follow->external_ref['number']);
        $this->assertStringStartsWith('github-42-', (string) $follow->branch);
        $this->assertNotSame($task->branch, $follow->branch);
        $brief = app(TaskTicketService::class)->followUpBrief($follow);
        $this->assertStringContainsString('https://github.com/acme/widget/pull/7', $brief);
        $this->assertStringContainsString('Login works now.', $brief);
    }
}

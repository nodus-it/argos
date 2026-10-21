<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\Capability;
use App\Enums\WorkspaceRole;
use App\Models\Task;
use App\Models\TaskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAccessTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Task $task;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
        $this->owner = $this->member(WorkspaceRole::Member);
        $this->task = Task::factory()->ownedBy($this->owner)->create(['workspace_id' => $this->workspace->id]);
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        WorkspaceMember::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function invite(User $user): void
    {
        TaskMember::query()->create(['task_id' => $this->task->id, 'user_id' => $user->id, 'invited_by' => $this->owner->id]);
    }

    public function test_owner_and_collaborators_work_in_a_task_others_only_read_it(): void
    {
        $collaborator = $this->member(WorkspaceRole::Member);
        $this->invite($collaborator);
        $otherMember = $this->member(WorkspaceRole::Member);
        $wsAdmin = $this->member(WorkspaceRole::Admin);
        $argosAdmin = User::factory()->admin()->create();
        $stranger = User::factory()->create();

        $matrix = [
            'view' => [$this->owner, $collaborator, $otherMember, $wsAdmin, $argosAdmin],
            'prompt' => [$this->owner, $collaborator, $wsAdmin, $argosAdmin],
            'resolveApproval' => [$this->owner, $collaborator, $wsAdmin, $argosAdmin],
            'ship' => [$this->owner, $collaborator, $wsAdmin, $argosAdmin],
            'review' => [$this->owner, $collaborator, $wsAdmin, $argosAdmin],
            'invite' => [$this->owner, $wsAdmin, $argosAdmin],
            'transfer' => [$this->owner, $wsAdmin, $argosAdmin],
            'archive' => [$this->owner, $wsAdmin, $argosAdmin],
        ];

        foreach ($matrix as $ability => $allowed) {
            foreach ([$this->owner, $collaborator, $otherMember, $wsAdmin, $argosAdmin, $stranger] as $user) {
                $this->assertSame(
                    in_array($user, $allowed, true),
                    $user->can($ability, $this->task->fresh()),
                    "{$ability} for {$user->email}",
                );
            }
        }
    }

    public function test_an_invited_viewer_works_in_that_task_only(): void
    {
        $viewer = $this->member(WorkspaceRole::Viewer);
        $this->invite($viewer);
        $otherTask = Task::factory()->ownedBy($this->owner)->create(['workspace_id' => $this->workspace->id]);

        $this->assertTrue($viewer->can('prompt', $this->task->fresh()));
        $this->assertFalse($viewer->can('invite', $this->task->fresh()));
        $this->assertFalse($viewer->can('prompt', $otherTask));
        $this->assertFalse($viewer->can('create', [Task::class, $this->workspace]));
        $this->assertFalse($viewer->can('editor', $this->workspace));
    }

    public function test_workspace_roles_grant_their_capabilities(): void
    {
        $access = app(TaskAccess::class);
        $member = $this->member(WorkspaceRole::Member);
        $viewer = $this->member(WorkspaceRole::Viewer);
        $wsAdmin = $this->member(WorkspaceRole::Admin);

        foreach ([Capability::TaskCreate, Capability::WorkspaceOperate, Capability::WorkspaceEditor] as $capability) {
            $this->assertTrue($access->allows($member, $capability, $this->workspace), $capability->value);
            $this->assertFalse($access->allows($viewer, $capability, $this->workspace), $capability->value);
        }
        foreach ([Capability::WorkspaceManage, Capability::WorkspaceSetup] as $capability) {
            $this->assertFalse($access->allows($member, $capability, $this->workspace), $capability->value);
            $this->assertTrue($access->allows($wsAdmin, $capability, $this->workspace), $capability->value);
        }
    }

    public function test_only_argos_admins_create_and_delete_workspaces(): void
    {
        $wsAdmin = $this->member(WorkspaceRole::Admin);
        $argosAdmin = User::factory()->admin()->create();

        $this->assertFalse($wsAdmin->can('create', Workspace::class));
        $this->assertTrue($argosAdmin->can('create', Workspace::class));
        $this->assertFalse($wsAdmin->can('delete', $this->workspace));
        $this->assertTrue($argosAdmin->can('delete', $this->workspace));
        $this->assertTrue($wsAdmin->can('update', $this->workspace));
    }
}

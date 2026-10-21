<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    public function test_workspace_abilities_by_role(): void
    {
        $wsAdmin = $this->member(WorkspaceRole::Admin);
        $member = $this->member(WorkspaceRole::Member);
        $viewer = $this->member(WorkspaceRole::Viewer);
        $stranger = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $matrix = [
            'view' => [$wsAdmin, $member, $viewer, $admin],
            'operate' => [$wsAdmin, $member, $admin],
            'editor' => [$wsAdmin, $member, $admin],
            'update' => [$wsAdmin, $admin],
            'setup' => [$wsAdmin, $admin],
            'manageMembers' => [$wsAdmin, $admin],
            // Deleting a workspace (and creating one) is for Argos admins only.
            'delete' => [$admin],
        ];

        foreach ($matrix as $ability => $allowed) {
            foreach ([$wsAdmin, $member, $viewer, $stranger, $admin] as $user) {
                $this->assertSame(
                    in_array($user, $allowed, true),
                    $user->can($ability, $this->workspace),
                    "{$ability} for {$user->email}",
                );
            }
        }

        foreach ([$wsAdmin, $member, $viewer, $stranger] as $user) {
            $this->assertFalse($user->can('create', Workspace::class));
        }
        $this->assertTrue($admin->can('create', Workspace::class));
    }

    public function test_collaboration_gate_closes_member_management(): void
    {
        $wsAdmin = $this->member(WorkspaceRole::Admin);
        config(['argos.workspaces.collaboration' => false]);

        $this->assertFalse($wsAdmin->can('manageMembers', $this->workspace));
        $this->assertTrue($wsAdmin->can('update', $this->workspace));
    }

    public function test_task_abilities_belong_to_the_people_of_the_task(): void
    {
        $owner = $this->member(WorkspaceRole::Member);
        $task = Task::factory()->ownedBy($owner)->create(['workspace_id' => $this->workspace->id]);
        $collaborator = $this->member(WorkspaceRole::Member);
        TaskMember::query()->create(['task_id' => $task->id, 'user_id' => $collaborator->id, 'invited_by' => $owner->id]);
        $otherMember = $this->member(WorkspaceRole::Member);
        $viewer = $this->member(WorkspaceRole::Viewer);
        $wsAdmin = $this->member(WorkspaceRole::Admin);
        $admin = User::factory()->admin()->create();
        $stranger = User::factory()->create();
        $everyone = [$owner, $collaborator, $otherMember, $viewer, $wsAdmin, $admin, $stranger];

        $collaborative = [$owner, $collaborator, $wsAdmin, $admin];
        $ownerOnly = [$owner, $wsAdmin, $admin];
        $matrix = [
            'view' => [$owner, $collaborator, $otherMember, $viewer, $wsAdmin, $admin],
            'prompt' => $collaborative,
            'interrupt' => $collaborative,
            'resolveApproval' => $collaborative,
            'changeMode' => $collaborative,
            'update' => $collaborative,
            'ship' => $collaborative,
            'review' => $collaborative,
            'invite' => $ownerOnly,
            'transfer' => $ownerOnly,
            'archive' => $ownerOnly,
            'delete' => $ownerOnly,
        ];

        foreach ($matrix as $ability => $allowed) {
            foreach ($everyone as $i => $user) {
                $this->assertSame(in_array($user, $allowed, true), $user->can($ability, $task), "{$ability} for user #{$i}");
            }
        }
    }

    public function test_creating_a_task_needs_a_usable_agent(): void
    {
        $editor = $this->member(WorkspaceRole::Member);
        $this->assertFalse($editor->can('create', [Task::class, $this->workspace]));

        AgentCredential::factory()->ownedBy($editor)->create();
        $this->assertTrue($editor->can('create', [Task::class, $this->workspace]));

        $viewer = $this->member(WorkspaceRole::Viewer);
        AgentCredential::factory()->ownedBy($viewer)->create();
        $this->assertFalse($viewer->can('create', [Task::class, $this->workspace]));
    }

    public function test_agent_policy_separates_own_shared_and_foreign_agents(): void
    {
        $me = User::factory()->create();
        $own = AgentCredential::factory()->ownedBy($me)->create();
        $shared = AgentCredential::factory()->create();
        $foreign = AgentCredential::factory()->ownedBy(User::factory()->create())->create();

        $this->assertTrue($me->can('update', $own));
        $this->assertFalse($me->can('update', $shared));
        $this->assertFalse($me->can('view', $foreign));
        $this->assertTrue($me->can('use', $shared));
        $this->assertFalse($me->can('use', $foreign));
        $this->assertTrue(User::factory()->admin()->create()->can('update', $foreign));
    }

    public function test_workspaces_can_be_disabled_platform_wide(): void
    {
        config(['argos.workspaces.enabled' => false]);
        $user = User::factory()->create();

        $this->assertFalse($user->can('create', Workspace::class));
        $this->assertFalse($user->can('viewAny', Workspace::class));
    }
}

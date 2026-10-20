<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\AgentCredential;
use App\Models\Thread;
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
        $owner = $this->member(WorkspaceRole::Owner);
        $editor = $this->member(WorkspaceRole::Editor);
        $viewer = $this->member(WorkspaceRole::Viewer);
        $stranger = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $matrix = [
            'view' => [$owner, $editor, $viewer, $admin],
            'operate' => [$owner, $editor, $admin],
            'update' => [$owner, $admin],
            'delete' => [$owner, $admin],
            'manageMembers' => [$owner, $admin],
        ];

        foreach ($matrix as $ability => $allowed) {
            foreach ([$owner, $editor, $viewer, $stranger, $admin] as $user) {
                $this->assertSame(
                    in_array($user, $allowed, true),
                    $user->can($ability, $this->workspace),
                    "{$ability} for {$user->email}",
                );
            }
        }
    }

    public function test_collaboration_gate_closes_member_management(): void
    {
        $owner = $this->member(WorkspaceRole::Owner);
        config(['argos.workspaces.collaboration' => false]);

        $this->assertFalse($owner->can('manageMembers', $this->workspace));
        $this->assertTrue($owner->can('update', $this->workspace));
    }

    public function test_thread_abilities_require_an_operating_role(): void
    {
        $thread = Thread::factory()->create(['workspace_id' => $this->workspace->id]);
        $editor = $this->member(WorkspaceRole::Editor);
        $viewer = $this->member(WorkspaceRole::Viewer);
        $admin = User::factory()->admin()->create();

        foreach (['prompt', 'interrupt', 'resolveApproval', 'changeMode'] as $ability) {
            $this->assertTrue($editor->can($ability, $thread), $ability);
            $this->assertFalse($viewer->can($ability, $thread), $ability);
            // Admins read everything but act only as members.
            $this->assertFalse($admin->can($ability, $thread), $ability);
        }
        $this->assertTrue($viewer->can('view', $thread));
        $this->assertTrue($admin->can('view', $thread));
        $this->assertFalse(User::factory()->create()->can('view', $thread));
    }

    public function test_creating_a_thread_needs_a_usable_agent(): void
    {
        $editor = $this->member(WorkspaceRole::Editor);
        $this->assertFalse($editor->can('create', [Thread::class, $this->workspace]));

        AgentCredential::factory()->ownedBy($editor)->create();
        $this->assertTrue($editor->can('create', [Thread::class, $this->workspace]));

        $viewer = $this->member(WorkspaceRole::Viewer);
        AgentCredential::factory()->ownedBy($viewer)->create();
        $this->assertFalse($viewer->can('create', [Thread::class, $this->workspace]));
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

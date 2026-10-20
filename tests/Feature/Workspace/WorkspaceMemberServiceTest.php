<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceMemberServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkspaceMemberService $members;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->members = app(WorkspaceMemberService::class);
        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create();
        $this->members->addOwner($this->workspace, $this->owner);
    }

    public function test_owner_adds_and_changes_members(): void
    {
        $pair = User::factory()->create();
        $this->members->add($this->workspace, $this->owner, $pair, WorkspaceRole::Editor);
        $this->assertSame(WorkspaceRole::Editor, $this->workspace->roleOf($pair));

        $this->members->changeRole($this->workspace, $pair, WorkspaceRole::Viewer);
        $this->assertSame(WorkspaceRole::Viewer, $this->workspace->roleOf($pair));

        $this->members->remove($this->workspace, $pair);
        $this->assertNull($this->workspace->roleOf($pair));
    }

    public function test_adding_members_is_blocked_when_the_collaboration_gate_is_closed(): void
    {
        config(['argos.workspaces.collaboration' => false]);

        $this->expectException(WorkspaceException::class);
        $this->members->add($this->workspace, $this->owner, User::factory()->create(), WorkspaceRole::Editor);
    }

    public function test_the_owner_is_added_even_without_collaboration(): void
    {
        config(['argos.workspaces.collaboration' => false]);
        $workspace = Workspace::factory()->create();
        $this->members->addOwner($workspace, $this->owner);

        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($this->owner));
    }

    public function test_the_last_owner_cannot_leave_or_be_demoted(): void
    {
        foreach ([fn () => $this->members->remove($this->workspace, $this->owner), fn () => $this->members->changeRole($this->workspace, $this->owner, WorkspaceRole::Editor)] as $attempt) {
            try {
                $attempt();
                $this->fail('expected WorkspaceException');
            } catch (WorkspaceException) {
                $this->assertSame(WorkspaceRole::Owner, $this->workspace->roleOf($this->owner));
            }
        }

        $second = User::factory()->create();
        $this->members->add($this->workspace, $this->owner, $second, WorkspaceRole::Owner);
        $this->members->changeRole($this->workspace, $this->owner, WorkspaceRole::Editor);
        $this->assertSame(WorkspaceRole::Editor, $this->workspace->roleOf($this->owner));
    }
}

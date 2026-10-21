<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Broadcasting\TaskStream;
use App\Enums\WorkspaceRole;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\TaskCreate;
use App\Mcp\Tools\TaskPlanDecide;
use App\Mcp\Tools\WorkspaceGet;
use App\Mcp\Tools\WorkspaceList;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkspaceToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_list_and_get_their_workspaces_with_open_tasks(): void
    {
        Bus::fake([ProvisionWorkspaceJob::class, AdvanceAutoTaskJob::class]);
        Event::fake([TaskStream::class]);
        $user = User::factory()->create();
        AgentCredential::factory()->ownedBy($user)->create();
        $workspace = Workspace::factory()->create(['name' => 'widget']);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
        $other = Workspace::factory()->create(['name' => 'other']);

        ArgosServer::actingAs($user)
            ->tool(TaskCreate::class, ['workspace' => 'widget', 'prompt' => 'Add priorities', 'plan' => 'auto'])
            ->assertOk();
        Task::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Old one', 'archived_at' => now()]);

        ArgosServer::actingAs($user)->tool(WorkspaceList::class, [])->assertOk()->assertSee($workspace->id)->assertDontSee($other->id);
        ArgosServer::actingAs($user)->tool(WorkspaceGet::class, ['workspace' => 'widget'])->assertOk()
            ->assertSee('"origin": "mcp"')
            ->assertSee('test-org/test-repo')
            ->assertDontSee('Old one');
    }

    public function test_other_users_workspaces_are_invisible(): void
    {
        $workspace = Workspace::factory()->create();

        ArgosServer::actingAs(User::factory()->create())
            ->tool(WorkspaceGet::class, ['workspace' => $workspace->id])
            ->assertHasErrors();
    }

    public function test_errors_are_reported_as_tool_errors(): void
    {
        // A member without an agent cannot start a task.
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'widget']);
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);

        ArgosServer::actingAs($user)
            ->tool(TaskCreate::class, ['workspace' => 'widget', 'prompt' => 'x'])
            ->assertHasErrors();
        ArgosServer::actingAs($user)
            ->tool(TaskPlanDecide::class, ['task' => 'nope', 'decision' => 'approve'])
            ->assertHasErrors();
    }
}

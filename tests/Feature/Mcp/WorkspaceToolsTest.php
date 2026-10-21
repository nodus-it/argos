<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Broadcasting\ThreadStream;
use App\Enums\WorkspaceOrigin;
use App\Jobs\Workspace\AdvanceAutoThreadJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\ThreadPlanDecide;
use App\Mcp\Tools\WorkspaceCreate;
use App\Mcp\Tools\WorkspaceGet;
use App\Mcp\Tools\WorkspaceList;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkspaceToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_create_starts_a_run_for_the_signed_in_user(): void
    {
        Bus::fake([ProvisionWorkspaceJob::class, AdvanceAutoThreadJob::class]);
        Event::fake([ThreadStream::class]);
        $user = User::factory()->create();
        AgentCredential::factory()->ownedBy($user)->create();
        RepoProfile::factory()->create(['name' => 'widget']);

        ArgosServer::actingAs($user)
            ->tool(WorkspaceCreate::class, ['project' => 'widget', 'prompt' => 'Add priorities', 'plan' => 'auto'])
            ->assertOk()
            ->assertSee('"state": "queued"');

        $workspace = Workspace::query()->sole();
        $this->assertSame(WorkspaceOrigin::Mcp, $workspace->origin);
        $this->assertSame('auto', Thread::query()->sole()->auto_config['plan']);

        ArgosServer::actingAs($user)->tool(WorkspaceList::class, [])->assertOk()->assertSee($workspace->id);
        ArgosServer::actingAs($user)->tool(WorkspaceGet::class, ['workspace' => $workspace->id])->assertOk()->assertSee('"origin": "mcp"');
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
        $user = User::factory()->create();
        RepoProfile::factory()->create(['name' => 'widget']);

        ArgosServer::actingAs($user)
            ->tool(WorkspaceCreate::class, ['project' => 'widget', 'prompt' => 'x'])
            ->assertHasErrors();
        ArgosServer::actingAs($user)
            ->tool(ThreadPlanDecide::class, ['thread' => 'nope', 'decision' => 'approve'])
            ->assertHasErrors();
    }
}

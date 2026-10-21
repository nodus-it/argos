<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Broadcasting\TaskStream;
use App\Enums\TaskOrigin;
use App\Enums\WorkspaceRole;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\TaskCreate;
use App\Mcp\Tools\TaskGet;
use App\Mcp\Tools\TaskShip;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class TaskToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProvisionWorkspaceJob::class, AdvanceAutoTaskJob::class]);
        Event::fake([TaskStream::class]);
        $this->user = User::factory()->create(['name' => 'Ada']);
        AgentCredential::factory()->ownedBy($this->user)->create();
        $this->workspace = Workspace::factory()->create(['name' => 'widget']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Member]);
    }

    public function test_task_create_starts_an_automatic_task_in_the_workspace(): void
    {
        ArgosServer::actingAs($this->user)
            ->tool(TaskCreate::class, ['workspace' => 'widget', 'prompt' => 'Add priorities', 'title' => 'Priorities', 'plan' => 'auto', 'base_branch' => 'develop', 'auto_merge' => true])
            ->assertOk()
            ->assertSee('"auto_merge": true')
            ->assertSee('"state": "queued"')
            ->assertSee('"origin": "mcp"');

        $task = Task::query()->sole();
        $this->assertSame($this->workspace->id, $task->workspace_id);
        $this->assertSame(TaskOrigin::Mcp, $task->origin);
        $this->assertSame($this->user->id, $task->owner_user_id);
        $this->assertSame('Priorities', $task->title);
        $this->assertSame('develop', $task->base_branch);
        $this->assertSame('auto', $task->auto_config['plan']);
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_task_create_refuses_unknown_workspaces_and_viewers(): void
    {
        ArgosServer::actingAs($this->user)
            ->tool(TaskCreate::class, ['workspace' => 'nope', 'prompt' => 'x'])
            ->assertHasErrors();

        $viewer = User::factory()->create();
        AgentCredential::factory()->ownedBy($viewer)->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        ArgosServer::actingAs($viewer)
            ->tool(TaskCreate::class, ['workspace' => 'widget', 'prompt' => 'x'])
            ->assertHasErrors();

        $this->assertSame(0, Task::query()->count());
    }

    public function test_task_get_returns_any_task_of_a_visible_workspace(): void
    {
        $colleague = User::factory()->create(['name' => 'Grace']);
        $task = Task::factory()->ownedBy($colleague)->create(['workspace_id' => $this->workspace->id, 'title' => 'Dark mode']);

        ArgosServer::actingAs($this->user)
            ->tool(TaskGet::class, ['task' => $task->id])
            ->assertOk()
            ->assertSee('Dark mode')
            ->assertSee('Grace');

        ArgosServer::actingAs(User::factory()->create())
            ->tool(TaskGet::class, ['task' => $task->id])
            ->assertHasErrors();
    }

    public function test_task_ship_ships_the_task_and_returns_the_pull_request(): void
    {
        $task = Task::factory()->ownedBy($this->user)->create(['workspace_id' => $this->workspace->id, 'title' => 'Dark mode']);
        $ship = Mockery::mock(WorkspaceShipService::class);
        $ship->shouldReceive('draft')->once()->withArgs(fn (Task $t, User $by): bool => $t->is($task) && $by->is($this->user))
            ->andReturn(['subject' => '', 'body' => 'Body']);
        $ship->shouldReceive('ship')->once()
            ->withArgs(fn (Task $t, User $by, string $subject, string $body, bool $draft): bool => $t->is($task) && $subject === 'Dark mode' && $body === 'Body' && $draft)
            ->andReturn('https://github.com/test-org/test-repo/pull/7');
        $this->app->instance(WorkspaceShipService::class, $ship);

        ArgosServer::actingAs($this->user)
            ->tool(TaskShip::class, ['task' => $task->id, 'draft' => true])
            ->assertOk()
            ->assertSee('https://github.com/test-org/test-repo/pull/7');
    }

    public function test_task_ship_reports_a_refusal_as_a_tool_error(): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $ship = Mockery::mock(WorkspaceShipService::class);
        $ship->shouldReceive('draft')->andThrow(new AuthorizationException('This action is unauthorized.'));
        $this->app->instance(WorkspaceShipService::class, $ship);

        ArgosServer::actingAs($this->user)
            ->tool(TaskShip::class, ['task' => $task->id])
            ->assertHasErrors();
        ArgosServer::actingAs($this->user)
            ->tool(TaskShip::class, ['task' => 'nope'])
            ->assertHasErrors();
    }

    public function test_only_the_owner_or_a_collaborator_may_ship(): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->assertFalse($this->user->can('ship', $task));
        $this->assertTrue($task->owner->can('ship', $task));
    }
}

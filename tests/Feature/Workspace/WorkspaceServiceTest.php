<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Events\Workspace\WorkspaceCreated;
use App\Events\Workspace\WorkspaceDeleted;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceService;
use App\Services\Workspace\WorkspaceTeardown;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkspaceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(): WorkspaceService
    {
        return app(WorkspaceService::class);
    }

    /** @return array<string, mixed> */
    private function attributes(array $overrides = []): array
    {
        return [
            'name' => 'Login Redesign',
            'repo_url' => 'https://github.com/acme/widget',
            'platform' => 'github',
            'auth_method' => 'pat',
            'token' => 'ghp-test',
            'default_branch' => 'develop',
            ...$overrides,
        ];
    }

    public function test_create_records_the_admin_and_starts_stopped(): void
    {
        Event::fake([WorkspaceCreated::class]);
        $user = User::factory()->create(['is_admin' => true]);

        $workspace = $this->service()->createWorkspace($user, $this->attributes());

        $this->assertSame('login-redesign', $workspace->slug);
        $this->assertSame('https://github.com/acme/widget', $workspace->repo_url);
        $this->assertSame('develop', $workspace->default_branch);
        $this->assertSame(WorkspaceStatus::Stopped, $workspace->status);
        $this->assertSame($user->id, $workspace->created_by);
        $this->assertSame(64, strlen($workspace->daemon_token));
        $this->assertSame(WorkspaceRole::Admin, $workspace->roleOf($user));
        // The container starts when someone opens the workspace, not on create.
        Queue::assertNotPushed(ProvisionWorkspaceJob::class);
        Event::assertDispatched(WorkspaceCreated::class);
    }

    public function test_new_workspaces_have_previews_on(): void
    {
        $workspace = $this->service()->createWorkspace(User::factory()->create(['is_admin' => true]), $this->attributes());

        $this->assertTrue($workspace->fresh()->live_demo_enabled);
    }

    public function test_only_argos_admins_create_workspaces(): void
    {
        $this->expectException(AuthorizationException::class);
        try {
            $this->service()->createWorkspace(User::factory()->create(), $this->attributes());
        } finally {
            $this->assertSame(0, Workspace::query()->count());
        }
    }

    public function test_operational_columns_cannot_be_set_through_the_attributes(): void
    {
        $workspace = $this->service()->register(User::factory()->create(), $this->attributes([
            'slug' => 'hijack',
            'status' => WorkspaceStatus::Running,
            'daemon_token' => 'known',
        ]));

        $this->assertSame('login-redesign', $workspace->slug);
        $this->assertSame(WorkspaceStatus::Stopped, $workspace->status);
        $this->assertNotSame('known', $workspace->daemon_token);
    }

    public function test_capacity_is_enforced_without_evicting(): void
    {
        config(['argos.workspaces.max_active' => 2]);
        $running = Workspace::factory()->count(2)->create();
        $stopped = Workspace::factory()->stopped()->create();

        try {
            $this->service()->start($stopped);
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertSame(WorkspaceStatus::Stopped, $stopped->fresh()->status);
            $running->each(fn (Workspace $ws) => $this->assertSame(WorkspaceStatus::Running, $ws->fresh()->status));
            Queue::assertNothingPushed();
        }
    }

    public function test_capacity_zero_disables_the_cap(): void
    {
        config(['argos.workspaces.max_active' => 0]);
        Workspace::factory()->count(3)->create();

        $this->service()->ensureCapacity();
        $this->assertTrue(true);
    }

    public function test_start_and_stop_record_intent_and_dispatch(): void
    {
        $workspace = Workspace::factory()->stopped()->create();

        $this->service()->start($workspace);
        $this->assertSame(WorkspaceStatus::Starting, $workspace->fresh()->status);
        Queue::assertPushed(ProvisionWorkspaceJob::class);

        $workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $this->service()->stop($workspace);
        $this->assertSame(WorkspaceStatus::Stopping, $workspace->fresh()->status);
        Queue::assertPushed(StopWorkspaceJob::class);
    }

    public function test_starting_a_running_workspace_is_a_no_op(): void
    {
        $this->service()->start(Workspace::factory()->create());

        Queue::assertNothingPushed();
    }

    public function test_delete_purges_resources_and_soft_deletes(): void
    {
        Event::fake([WorkspaceDeleted::class]);
        $workspace = Workspace::factory()->create(['slug' => 'gone']);
        Task::factory()->create(['workspace_id' => $workspace->id, 'slug' => 'login']);
        // The task slugs name the per-task databases and preview stacks.
        $this->mock(WorkspaceTeardown::class)->shouldReceive('purge')->once()->with('gone', ['login']);

        $this->service()->deleteWorkspace($workspace);

        $this->assertSoftDeleted($workspace);
        Event::assertDispatched(WorkspaceDeleted::class);
    }

    public function test_touch_is_throttled(): void
    {
        $workspace = Workspace::factory()->create(['last_activity_at' => now()->subSeconds(10)]);
        $before = $workspace->last_activity_at;

        $this->service()->touch($workspace);
        $this->assertTrue($workspace->fresh()->last_activity_at->equalTo($before));

        $workspace->forceFill(['last_activity_at' => now()->subMinutes(5)])->save();
        $this->service()->touch($workspace);
        $this->assertTrue($workspace->fresh()->last_activity_at->greaterThan(now()->subMinute()));
    }
}

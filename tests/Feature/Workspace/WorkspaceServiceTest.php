<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Events\Workspace\WorkspaceCreated;
use App\Events\Workspace\WorkspaceDeleted;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceService;
use App\Services\Workspace\WorkspaceTeardown;
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

    public function test_create_records_the_workspace_owner_and_queues_provisioning(): void
    {
        Event::fake([WorkspaceCreated::class]);
        $user = User::factory()->create();
        $profile = RepoProfile::factory()->create(['default_branch' => 'develop']);

        $workspace = $this->service()->createWorkspace($user, $profile, 'Login Redesign');

        $this->assertSame('login-redesign', $workspace->slug);
        $this->assertSame('ws/login-redesign', $workspace->branch);
        $this->assertSame('develop', $workspace->base_branch);
        $this->assertSame(WorkspaceStatus::Provisioning, $workspace->status);
        $this->assertSame(64, strlen($workspace->daemon_token));
        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf($user));
        Queue::assertPushed(ProvisionWorkspaceJob::class, fn (ProvisionWorkspaceJob $job): bool => $job->workspaceId === $workspace->id);
        Event::assertDispatched(WorkspaceCreated::class);
    }

    public function test_an_explicit_base_branch_wins(): void
    {
        $workspace = $this->service()->createWorkspace(User::factory()->create(), RepoProfile::factory()->create(), 'X', 'release/1.0');

        $this->assertSame('release/1.0', $workspace->base_branch);
    }

    public function test_capacity_is_enforced_without_evicting(): void
    {
        config(['argos.workspaces.max_active' => 2]);
        Workspace::factory()->count(2)->create();
        $stopped = Workspace::factory()->stopped()->create();

        try {
            $this->service()->createWorkspace(User::factory()->create(), RepoProfile::factory()->create(), 'Third');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertSame(3, Workspace::query()->count());
        }

        $this->expectException(WorkspaceException::class);
        $this->service()->start($stopped);
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
        $this->mock(WorkspaceTeardown::class)->shouldReceive('purge')->once()->with('gone');

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

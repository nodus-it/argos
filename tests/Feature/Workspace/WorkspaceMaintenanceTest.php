<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\ThreadStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\ReapOrphanedWorkspacesJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContainerManager;
use App\Services\Workspace\WorkspaceTeardown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkspaceMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_workspaces_stop_unless_a_thread_is_busy(): void
    {
        Queue::fake();
        config(['argos.workspaces.idle_minutes' => 60]);
        $idle = Workspace::factory()->create(['last_activity_at' => now()->subHours(2)]);
        $active = Workspace::factory()->create(['last_activity_at' => now()->subMinutes(5)]);
        $busy = Workspace::factory()->create(['last_activity_at' => now()->subHours(2)]);
        Thread::factory()->create(['workspace_id' => $busy->id, 'status' => ThreadStatus::WaitingInput]);
        $stopped = Workspace::factory()->stopped()->create(['last_activity_at' => now()->subDay()]);

        $this->artisan('argos:workspaces:idle-stop')->assertSuccessful();

        $this->assertSame(WorkspaceStatus::Stopping, $idle->fresh()->status);
        $this->assertSame(WorkspaceStatus::Running, $active->fresh()->status);
        $this->assertSame(WorkspaceStatus::Running, $busy->fresh()->status);
        $this->assertSame(WorkspaceStatus::Stopped, $stopped->fresh()->status);
        Queue::assertPushed(StopWorkspaceJob::class, 1);
    }

    public function test_idle_stop_can_be_disabled(): void
    {
        Queue::fake();
        config(['argos.workspaces.idle_minutes' => 0]);
        Workspace::factory()->create(['last_activity_at' => now()->subDay()]);

        $this->artisan('argos:workspaces:idle-stop')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_orphaned_workspace_containers_are_purged(): void
    {
        Workspace::factory()->create(['slug' => 'alive']);
        $gone = Workspace::factory()->create(['slug' => 'deleted-one']);
        $gone->delete();
        $this->mock(WorkspaceContainerManager::class)->shouldReceive('containerSlugs')->andReturn(['alive', 'deleted-one', 'never-known']);
        $teardown = $this->mock(WorkspaceTeardown::class);
        $teardown->shouldReceive('purge')->once()->with('deleted-one');
        $teardown->shouldReceive('purge')->once()->with('never-known');

        app()->call([new ReapOrphanedWorkspacesJob, 'handle']);
    }

    public function test_the_sweep_command_queues_the_job(): void
    {
        Queue::fake();

        $this->artisan('argos:workspaces:reap-orphans')->assertSuccessful();

        Queue::assertPushed(ReapOrphanedWorkspacesJob::class);
    }
}

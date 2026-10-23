<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\DemoStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Events\Workspace\WorkspaceStarted;
use App\Integrations\WorkspaceDaemon\Requests\InitWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\StopTask;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Models\Demo;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkerStack;
use App\Models\Workspace;
use App\Services\Demo\DemoDeployer;
use App\Services\Worker\WorkerSidecarManager;
use App\Services\Worker\WorkerSidecars;
use App\Services\Workspace\WorkspaceContainerManager;
use App\Services\Workspace\WorkspacePreviewService;
use App\Services\Workspace\WorkspaceProvisioner;
use App\Workers\Agents\AgentSpec;
use App\Workers\Compose\ResolvedWorkerImage;
use App\Workers\Compose\WorkerImageResolver;
use App\Workers\Compose\WorkspaceImageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class WorkspaceProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private string $traefikDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->traefikDir = sys_get_temp_dir().'/argos-traefik-'.uniqid();
        config()->set('argos.preview.traefik_dir', $this->traefikDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->traefikDir.'/*') ?: []);
        @rmdir($this->traefikDir);
        parent::tearDown();
    }

    private function bindDocker(?callable $containers = null): void
    {
        $spec = (new \ReflectionClass(AgentSpec::class))->newInstanceWithoutConstructor();
        $resolved = new ResolvedWorkerImage(new WorkerStack, $spec, 'argos-stack:t', 'argos-worker:t');
        $this->mock(WorkerImageResolver::class)->shouldReceive('resolveForWorkspace')->andReturn($resolved);
        $this->mock(WorkspaceImageBuilder::class)->shouldReceive('ensure')->andReturn(['tag' => 'argos-workspace:t', 'log' => '']);
        $this->mock(WorkerSidecarManager::class, function ($mock): void {
            $mock->shouldReceive('startForWorkspace')->andReturn(new WorkerSidecars);
            $mock->shouldReceive('stopWorkspaceContainers');
        });
        $this->mock(WorkspaceContainerManager::class, $containers ?? function ($mock): void {
            $mock->shouldReceive('prepareVolumes')->once();
            $mock->shouldReceive('run')->once()->withArgs(fn ($ws, $image) => $image === 'argos-workspace:t');
            $mock->shouldReceive('waitForHealth')->once()->andReturn(['version' => '0.1.0']);
            $mock->shouldReceive('stop');
        });
    }

    public function test_boot_clones_the_default_branch_through_the_daemon_and_marks_running(): void
    {
        Event::fake([WorkspaceStarted::class]);
        $this->bindDocker();
        $sent = [];
        Saloon::fake([InitWorkspace::class => function (PendingRequest $request) use (&$sent) {
            $sent = $request->body()->all();

            return MockResponse::make(['status' => 'ready']);
        }]);
        $creator = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Provisioning, 'created_by' => $creator->id, 'default_branch' => 'develop']);

        app(WorkspaceProvisioner::class)->boot($workspace);

        $workspace->refresh();
        $this->assertSame(WorkspaceStatus::Running, $workspace->status);
        $this->assertSame($workspace->containerName(), $workspace->container_name);
        // The main clone checks out the default branch; tasks get worktrees later.
        $this->assertArrayNotHasKey('branch', $sent);
        $this->assertSame('develop', $sent['base_branch']);
        $this->assertSame('ada@example.com', $sent['git_user_email']);
        $this->assertSame($workspace->repo_url, $sent['repo_url']);
        $this->assertArrayNotHasKey('repo_token', $sent);
        Event::assertDispatched(WorkspaceStarted::class);
        $this->assertFileExists($this->traefikDir.'/code-'.$workspace->slug.'.yml');
    }

    public function test_boot_carries_on_with_tasks_still_preparing(): void
    {
        Event::fake([WorkspaceStarted::class]);
        Bus::fake([PrepareTaskJob::class]);
        $this->bindDocker();
        Saloon::fake([InitWorkspace::class => MockResponse::make(['status' => 'ready'])]);
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Provisioning]);
        $waiting = Task::factory()->create(['workspace_id' => $workspace->id, 'status' => TaskStatus::Preparing]);
        Task::factory()->create(['workspace_id' => $workspace->id, 'status' => TaskStatus::Active]);
        Task::factory()->create(['status' => TaskStatus::Preparing]);

        app(WorkspaceProvisioner::class)->boot($workspace);

        Bus::assertDispatchedTimes(PrepareTaskJob::class, 1);
        Bus::assertDispatched(PrepareTaskJob::class, fn (PrepareTaskJob $job): bool => $job->taskId === $waiting->id);
    }

    public function test_init_failures_mark_the_workspace_failed_with_a_readable_message(): void
    {
        $this->bindDocker();
        Saloon::fake([InitWorkspace::class => MockResponse::make(['error' => ['code' => 'auth', 'message' => 'Authentication failed']], 422)]);
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Provisioning]);

        try {
            app(WorkspaceProvisioner::class)->boot($workspace);
            $this->fail('expected exception');
        } catch (\Throwable) {
            $workspace->refresh();
            $this->assertSame(WorkspaceStatus::Failed, $workspace->status);
            $this->assertStringContainsString('auth', (string) $workspace->status_message);
            $this->assertStringContainsString('Authentication failed', (string) $workspace->status_message);
        }
    }

    public function test_docker_failures_mark_the_workspace_failed(): void
    {
        $this->bindDocker(function ($mock): void {
            $mock->shouldReceive('prepareVolumes')->andThrow(new RuntimeException('docker volume create failed'));
        });
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Provisioning]);

        $this->expectException(RuntimeException::class);
        try {
            app(WorkspaceProvisioner::class)->boot($workspace);
        } finally {
            $this->assertSame(WorkspaceStatus::Failed, $workspace->fresh()->status);
            $this->assertSame('docker volume create failed', $workspace->fresh()->status_message);
        }
    }

    public function test_shutdown_stops_every_task_preview_of_the_workspace(): void
    {
        $this->bindDocker(function ($mock): void {
            $mock->shouldReceive('stop')->once();
        });
        $this->mock(DemoDeployer::class)->shouldReceive('teardown')->twice();
        $workspace = Workspace::factory()->create();
        $live = Demo::factory()->live()->create(['task_id' => Task::factory()->create(['workspace_id' => $workspace->id])->id]);
        $building = Demo::factory()->create(['task_id' => Task::factory()->create(['workspace_id' => $workspace->id])->id]);
        $foreign = Demo::factory()->live()->create();

        app(WorkspaceProvisioner::class)->shutdown($workspace);

        $this->assertSame(DemoStatus::Stopped, $live->fresh()->status);
        $this->assertSame(DemoStatus::Stopped, $building->fresh()->status);
        $this->assertSame(DemoStatus::Live, $foreign->fresh()->status);
    }

    public function test_shutdown_closes_open_sessions_and_stops_the_container(): void
    {
        $this->bindDocker(function ($mock): void {
            $mock->shouldReceive('stop')->once();
        });
        $this->mock(WorkspacePreviewService::class)->shouldReceive('stopAll')->once();
        $stopped = [];
        Saloon::fake([StopTask::class => function (PendingRequest $request) use (&$stopped) {
            $stopped[] = $request->getUrl();

            return MockResponse::make([], 202);
        }]);
        $workspace = Workspace::factory()->create();
        $open = Task::factory()->open()->create(['workspace_id' => $workspace->id]);
        $closed = Task::factory()->create(['workspace_id' => $workspace->id]);

        app(WorkspaceProvisioner::class)->shutdown($workspace);

        $this->assertCount(1, $stopped);
        $this->assertStringContainsString($open->id, $stopped[0]);
        $this->assertFalse($open->fresh()->session_open);
        $this->assertSame(SessionStatus::Closed, $open->fresh()->session_status);
        $this->assertSame(SessionStatus::Closed, $closed->fresh()->session_status);
        $this->assertSame(WorkspaceStatus::Stopped, $workspace->fresh()->status);
        $this->assertFileDoesNotExist($this->traefikDir.'/code-'.$workspace->slug.'.yml');
    }

    public function test_a_failed_shutdown_marks_the_workspace_failed(): void
    {
        $this->bindDocker(function ($mock): void {
            $mock->shouldReceive('stop')->once()->andThrow(new RuntimeException('docker stop failed'));
        });
        $this->mock(WorkspacePreviewService::class)->shouldReceive('stopAll')->once();
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Stopping]);

        try {
            app(WorkspaceProvisioner::class)->shutdown($workspace);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertSame(WorkspaceStatus::Failed, $workspace->fresh()->status);
            $this->assertSame('docker stop failed', $workspace->fresh()->status_message);
        }
    }
}

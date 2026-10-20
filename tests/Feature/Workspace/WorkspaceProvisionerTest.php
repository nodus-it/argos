<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\ThreadStatus;
use App\Enums\WorkspaceStatus;
use App\Events\Workspace\WorkspaceStarted;
use App\Integrations\WorkspaceDaemon\Requests\InitWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\StopThread;
use App\Models\Thread;
use App\Models\User;
use App\Models\WorkerStack;
use App\Models\Workspace;
use App\Services\Workflow\WorkerSidecarManager;
use App\Services\Workflow\WorkerSidecars;
use App\Services\Workspace\WorkspaceContainerManager;
use App\Services\Workspace\WorkspaceProvisioner;
use App\Workers\Agents\AgentSpec;
use App\Workers\Compose\ResolvedWorkerImage;
use App\Workers\Compose\WorkerImageResolver;
use App\Workers\Compose\WorkspaceImageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class WorkspaceProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function bindDocker(?callable $containers = null): void
    {
        $spec = (new \ReflectionClass(AgentSpec::class))->newInstanceWithoutConstructor();
        $resolved = new ResolvedWorkerImage(new WorkerStack, $spec, 'argos-stack:t', 'argos-worker:t');
        $this->mock(WorkerImageResolver::class)->shouldReceive('resolveForRepoProfile')->andReturn($resolved);
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

    public function test_boot_clones_through_the_daemon_and_marks_running(): void
    {
        Event::fake([WorkspaceStarted::class]);
        $this->bindDocker();
        $sent = [];
        Saloon::fake([InitWorkspace::class => function (PendingRequest $request) use (&$sent) {
            $sent = $request->body()->all();

            return MockResponse::make(['status' => 'ready']);
        }]);
        $creator = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Provisioning, 'created_by' => $creator->id, 'branch' => 'ws/login']);

        app(WorkspaceProvisioner::class)->boot($workspace);

        $workspace->refresh();
        $this->assertSame(WorkspaceStatus::Running, $workspace->status);
        $this->assertSame($workspace->containerName(), $workspace->container_name);
        $this->assertSame('ws/login', $sent['branch']);
        $this->assertSame('ada@example.com', $sent['git_user_email']);
        $this->assertSame($workspace->repoProfile->url, $sent['repo_url']);
        $this->assertArrayNotHasKey('repo_token', $sent);
        Event::assertDispatched(WorkspaceStarted::class);
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

    public function test_shutdown_closes_open_sessions_and_stops_the_container(): void
    {
        $this->bindDocker(function ($mock): void {
            $mock->shouldReceive('stop')->once();
        });
        $stopped = [];
        Saloon::fake([StopThread::class => function (PendingRequest $request) use (&$stopped) {
            $stopped[] = $request->getUrl();

            return MockResponse::make([], 202);
        }]);
        $workspace = Workspace::factory()->create();
        $open = Thread::factory()->open()->create(['workspace_id' => $workspace->id]);
        $closed = Thread::factory()->create(['workspace_id' => $workspace->id]);

        app(WorkspaceProvisioner::class)->shutdown($workspace);

        $this->assertCount(1, $stopped);
        $this->assertStringContainsString($open->id, $stopped[0]);
        $this->assertFalse($open->fresh()->session_open);
        $this->assertSame(ThreadStatus::Closed, $open->fresh()->status);
        $this->assertSame(ThreadStatus::Closed, $closed->fresh()->status);
        $this->assertSame(WorkspaceStatus::Stopped, $workspace->fresh()->status);
    }
}

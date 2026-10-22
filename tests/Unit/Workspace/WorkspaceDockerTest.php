<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Integrations\WorkspaceDaemon\Requests\GetHealth;
use App\Models\Workspace;
use App\Services\Worker\WorkerSidecarManager;
use App\Services\Worker\WorkerSidecars;
use App\Services\Workspace\WorkspaceContainerManager;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Records docker commands; answers `docker network inspect` with "missing". */
class RecordingWorkspaceSidecars extends WorkerSidecarManager
{
    /** @var list<list<string>> */
    public array $commands = [];

    protected function newProcess(array $cmd): Process
    {
        $this->commands[] = $cmd;

        return new Process([($cmd[1] ?? '') === 'network' && ($cmd[2] ?? '') === 'inspect' ? 'false' : 'true']);
    }

    protected function sleep(int $seconds): void {}
}

class RecordingContainerManager extends WorkspaceContainerManager
{
    /** @var list<list<string>> */
    public array $commands = [];

    public float $clock = 0;

    protected function newProcess(array $cmd): Process
    {
        $this->commands[] = $cmd;

        return new Process(['true']);
    }

    protected function now(): float
    {
        return $this->clock;
    }

    protected function sleep(int $seconds): void
    {
        $this->clock += $seconds;
    }
}

class WorkspaceDockerTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(array $services = [], array $env = []): Workspace
    {
        return Workspace::factory()->withBackingServices($services)->create([
            'slug' => 'login',
            'daemon_token' => 'daemon-secret',
            'worker_env' => $env === [] ? null : $env,
        ]);
    }

    /** @param list<string> $cmd */
    private function envOf(array $cmd): array
    {
        $env = [];
        foreach ($cmd as $i => $arg) {
            if ($arg === '-e') {
                [$k, $v] = explode('=', $cmd[$i + 1], 2);
                $env[$k] = $v;
            }
        }

        return $env;
    }

    public function test_run_command_wires_network_volumes_limits_and_daemon_env(): void
    {
        config(['argos.workspaces.internal_url' => 'http://nginx', 'argos.workspaces.cpus' => '3', 'argos.workspaces.memory' => '6g']);
        $workspace = $this->workspace();
        $sidecars = new WorkerSidecars('ws_login_svc', ['DB_HOST' => 'db'], ['ws_login_svc-db']);

        $cmd = app(WorkspaceContainerManager::class)->runCommand($workspace, 'argos-workspace:x', $sidecars);
        $joined = implode(' ', $cmd);
        $env = $this->envOf($cmd);

        $this->assertStringContainsString('--name argos-ws-login', $joined);
        $this->assertStringContainsString('--network argos_ws', $joined);
        $this->assertStringContainsString('--label argos.workspace=login', $joined);
        $this->assertStringContainsString('--cpus 3 --memory 6g', $joined);
        $this->assertStringContainsString('-v ws_login:/workspace', $joined);
        $this->assertStringContainsString('-v ws_login_agents:/home/agent/.claude-agents', $joined);
        // Hardened, and package caches of its own — never shared with other workspaces.
        $this->assertStringContainsString('--cap-drop ALL --security-opt no-new-privileges --pids-limit 4096', $joined);
        $this->assertStringContainsString('source=ws_login_cache,target=/home/agent/.composer/cache,volume-subpath=composer', $joined);
        $this->assertStringNotContainsString('composer_cache', $joined);
        $this->assertSame('daemon-secret', $env['ARGOS_DAEMON_TOKEN']);
        $this->assertSame("http://nginx/internal/workspaces/{$workspace->id}/events", $env['ARGOS_EVENTS_URL']);
        $this->assertSame("http://nginx/internal/workspaces/{$workspace->id}/repo-credential", $env['ARGOS_REPO_CREDENTIAL_URL']);
        $this->assertSame('db', $env['DB_HOST']);
        $this->assertArrayNotHasKey('CLAUDE_CODE_OAUTH_TOKEN', $env);
        $this->assertSame('argos-workspace:x', end($cmd));
    }

    public function test_workspace_resource_limits_override_the_installation_default(): void
    {
        config(['argos.workspaces.cpus' => '3', 'argos.workspaces.memory' => '6g']);
        $workspace = $this->workspace();
        $workspace->forceFill(['cpu_limit' => '1.50', 'memory_limit_mb' => 2048])->save();

        $joined = implode(' ', app(WorkspaceContainerManager::class)->runCommand($workspace->fresh(), 'img', new WorkerSidecars));

        $this->assertStringContainsString('--cpus 1.5 --memory 2048m', $joined);
    }

    public function test_workspace_env_cannot_override_daemon_wiring(): void
    {
        $workspace = $this->workspace([], [['name' => 'ARGOS_DAEMON_TOKEN', 'value' => 'evil'], ['name' => 'MEILI_KEY', 'value' => 'abc']]);

        $env = $this->envOf(app(WorkspaceContainerManager::class)->runCommand($workspace, 'img', new WorkerSidecars));

        $this->assertSame('daemon-secret', $env['ARGOS_DAEMON_TOKEN']);
        $this->assertSame('abc', $env['MEILI_KEY']);
    }

    public function test_run_recreates_the_container_and_joins_the_sidecar_network(): void
    {
        $manager = new RecordingContainerManager(app(WorkspaceDaemonClient::class));
        $manager->run($this->workspace(), 'img', new WorkerSidecars('ws_login_svc', [], []));

        $this->assertSame(['docker', 'rm', '-f', 'argos-ws-login'], $manager->commands[0]);
        $this->assertSame(['docker', 'run', '-d'], array_slice($manager->commands[1], 0, 3));
        $this->assertSame(['docker', 'network', 'connect', 'ws_login_svc', 'argos-ws-login'], $manager->commands[2]);
    }

    public function test_prepare_volumes_creates_and_chowns_the_volumes(): void
    {
        $manager = new RecordingContainerManager(app(WorkspaceDaemonClient::class));
        $manager->prepareVolumes($this->workspace());

        $this->assertSame('ws_login', end($manager->commands[0]));
        $this->assertSame('ws_login_agents', end($manager->commands[1]));
        $this->assertSame('ws_login_cache', end($manager->commands[2]));
        $this->assertStringContainsString('chown 1000:1000', (string) end($manager->commands[3]));
    }

    public function test_wait_for_health_polls_until_the_daemon_answers(): void
    {
        Saloon::fake([
            MockResponse::make(['error' => ['code' => 'starting']], 503),
            MockResponse::make(['version' => '0.1.0', 'tasks' => []], 200),
        ]);
        $manager = new RecordingContainerManager(app(WorkspaceDaemonClient::class));

        $this->assertSame('0.1.0', $manager->waitForHealth($this->workspace(), 30)['version']);
    }

    public function test_wait_for_health_gives_up_after_the_timeout(): void
    {
        Saloon::fake([GetHealth::class => MockResponse::make([], 503)]);
        $manager = new RecordingContainerManager(app(WorkspaceDaemonClient::class));

        $this->expectExceptionMessage('not healthy after 10s');
        $manager->waitForHealth($this->workspace(), 10);
    }

    public function test_workspace_sidecars_are_long_lived_with_data_volumes(): void
    {
        $manager = new RecordingWorkspaceSidecars;
        $sidecars = $manager->startForWorkspace($this->workspace(['mysql']));

        $run = collect($manager->commands)->first(fn (array $c): bool => ($c[1] ?? '') === 'run');
        $joined = implode(' ', $run);
        $this->assertStringContainsString('--name ws_login_svc-db', $joined);
        $this->assertStringContainsString('--restart unless-stopped', $joined);
        $this->assertStringContainsString('-v ws_login_svc_db:/var/lib/mysql', $joined);
        $this->assertStringContainsString('--label argos.workspace=login', $joined);
        $this->assertSame('ws_login_svc', $sidecars->network);
        $this->assertSame('db', $sidecars->env['DB_HOST']);
        $this->assertTrue(collect($manager->commands)->contains(fn (array $c): bool => array_slice($c, 0, 3) === ['docker', 'network', 'create']));
    }

    public function test_purge_removes_sidecar_data_and_network_by_slug(): void
    {
        $manager = new RecordingWorkspaceSidecars;
        $manager->purgeWorkspace('login');

        $flat = array_map(fn (array $c): string => implode(' ', $c), $manager->commands);
        $this->assertContains('docker volume rm -f ws_login_svc_db', $flat);
        $this->assertContains('docker volume rm -f ws_login_svc_redis', $flat);
        $this->assertContains('docker network rm ws_login_svc', $flat);
    }
}

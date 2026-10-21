<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use App\Models\Workspace;
use App\Services\Worker\WorkerSidecarManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Records the docker commands the manager issues and answers them with offline
 * `true`/`false` processes — no real Docker needed.
 */
class RecordingSidecarManager extends WorkerSidecarManager
{
    /** @var list<list<string>> */
    public array $commands = [];

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        $this->commands[] = $cmd;

        return new Process(['true']);
    }

    protected function sleep(int $seconds): void {}
}

class WorkerSidecarManagerTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $services */
    private function workspaceWithServices(array $services): Workspace
    {
        return Workspace::factory()->withBackingServices($services)->create();
    }

    public function test_no_sidecars_when_workspace_enables_none(): void
    {
        $manager = new RecordingSidecarManager;
        $sidecars = $manager->startForWorkspace($this->workspaceWithServices([]));

        $this->assertTrue($sidecars->isEmpty());
        $this->assertSame([], $manager->commands);
    }

    public function test_starts_services_with_workspace_label_and_collects_env(): void
    {
        $workspace = $this->workspaceWithServices(['mysql', 'redis']);
        $manager = new RecordingSidecarManager;
        $sidecars = $manager->startForWorkspace($workspace);

        $this->assertFalse($sidecars->isEmpty());
        $this->assertSame('ws_'.$workspace->slug.'_svc', $sidecars->network);
        $this->assertCount(2, $sidecars->containers);
        $this->assertSame('db', $sidecars->env['DB_HOST']);
        $this->assertSame('redis', $sidecars->env['REDIS_HOST']);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $manager->commands);

        // Containers carry the workspace label (orphan sweep) and a named data volume.
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'mariadb:11')
                && str_contains($c, WorkerSidecarManager::WORKSPACE_LABEL.'='.$workspace->slug)
                && str_contains($c, $sidecars->network.'_db:')),
            'expected a labelled mariadb container with a data volume',
        );
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'redis:7-alpine')),
            'expected a redis container to be started',
        );
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'docker exec') && str_contains($c, 'healthcheck.sh')),
            'expected a mysql readiness probe',
        );
    }

    public function test_custom_mysql_credentials_reach_container_and_connection_env(): void
    {
        $workspace = Workspace::factory()
            ->withBackingServices(['mysql'])
            ->withServiceConfig(['mysql' => ['database' => 'shop', 'username' => 'sa', 'password' => 'pw']])
            ->create();

        $manager = new RecordingSidecarManager;
        $sidecars = $manager->startForWorkspace($workspace);

        $this->assertSame('shop', $sidecars->env['DB_DATABASE']);
        $this->assertSame('sa', $sidecars->env['DB_USERNAME']);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $manager->commands);
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'MARIADB_DATABASE=shop')),
            'expected the mariadb container to use the custom database name',
        );
    }

    public function test_purge_removes_containers_volumes_and_network(): void
    {
        $manager = new RecordingSidecarManager;
        $manager->purgeWorkspace('demo');

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $manager->commands);

        $this->assertContains('docker rm -f ws_demo_svc-db', $joined);
        $this->assertContains('docker volume rm -f ws_demo_svc_db', $joined);
        $this->assertContains('docker network rm ws_demo_svc', $joined);
    }
}

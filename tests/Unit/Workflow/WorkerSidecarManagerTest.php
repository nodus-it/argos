<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Workflow\WorkerSidecarManager;
use App\Services\Workflow\WorkerSidecars;
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

    private function taskWithServices(array $services, string $name = 'my-task'): Task
    {
        $profile = RepoProfile::factory()->withBackingServices($services)->create();

        return Task::factory()->create(['repo_profile_id' => $profile->id, 'name' => $name]);
    }

    public function test_no_sidecars_for_non_test_phase(): void
    {
        $manager = new RecordingSidecarManager;
        $sidecars = $manager->start($this->taskWithServices(['mysql']), 'concept');

        $this->assertTrue($sidecars->isEmpty());
        $this->assertSame([], $manager->commands);
    }

    public function test_no_sidecars_when_profile_enables_none(): void
    {
        $manager = new RecordingSidecarManager;
        $sidecars = $manager->start($this->taskWithServices([]), 'implement');

        $this->assertTrue($sidecars->isEmpty());
        $this->assertSame([], $manager->commands);
    }

    public function test_starts_network_services_and_collects_env(): void
    {
        $manager = new RecordingSidecarManager;
        $sidecars = $manager->start($this->taskWithServices(['mysql', 'redis']), 'implement');

        $this->assertFalse($sidecars->isEmpty());
        $this->assertStringStartsWith('argos-run-my-task', (string) $sidecars->network);
        $this->assertCount(2, $sidecars->containers);

        // Worker-facing connection env for both services.
        $this->assertSame('db', $sidecars->env['DB_HOST']);
        $this->assertSame('redis', $sidecars->env['REDIS_HOST']);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $manager->commands);

        // The run network is created with the run labels so the orphan sweep
        // and abort can find it again.
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_starts_with($c, 'docker network create')
                && str_contains($c, (string) $sidecars->network)
                && str_contains($c, 'argos.role=network')
                && str_contains($c, 'argos.task=')),
            'expected the run network to be created with run labels',
        );
        // Sidecar containers carry the run labels too.
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'mariadb:11')
                && str_contains($c, 'argos.role=sidecar')),
            'expected a mariadb container to be started with run labels',
        );
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'mariadb:11')),
            'expected a mariadb container to be started',
        );
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'redis:7-alpine')),
            'expected a redis container to be started',
        );
        // Readiness probed via docker exec.
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'docker exec') && str_contains($c, 'healthcheck.sh')),
            'expected a mysql readiness probe',
        );
    }

    public function test_custom_mysql_credentials_reach_container_and_connection_env(): void
    {
        $profile = RepoProfile::factory()
            ->withBackingServices(['mysql'])
            ->withServiceConfig(['mysql' => ['database' => 'shop', 'username' => 'sa', 'password' => 'pw']])
            ->create();
        $task = Task::factory()->create(['repo_profile_id' => $profile->id, 'name' => 'cust']);

        $manager = new RecordingSidecarManager;
        $sidecars = $manager->start($task, 'implement');

        $this->assertSame('shop', $sidecars->env['DB_DATABASE']);
        $this->assertSame('sa', $sidecars->env['DB_USERNAME']);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $manager->commands);
        $this->assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'MARIADB_DATABASE=shop')),
            'expected the mariadb container to use the custom database name',
        );
    }

    public function test_stop_removes_containers_and_network(): void
    {
        $manager = new RecordingSidecarManager;
        $manager->stop(new WorkerSidecars('argos-run-x', ['DB_HOST' => 'db'], ['argos-run-x-db', 'argos-run-x-redis']));

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $manager->commands);

        $this->assertContains('docker rm -f argos-run-x-db', $joined);
        $this->assertContains('docker rm -f argos-run-x-redis', $joined);
        $this->assertContains('docker network rm argos-run-x', $joined);
    }

    public function test_stop_is_a_noop_for_empty_handle(): void
    {
        $manager = new RecordingSidecarManager;
        $manager->stop(new WorkerSidecars);

        $this->assertSame([], $manager->commands);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\BackingService;
use App\Models\Workspace;
use App\Services\Project\BackingServiceResolver;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Boots the backing services a repo profile enabled (MySQL, Redis) as sidecars
 * of a workspace. The Manager owns the Docker socket; the workspace stays
 * socket-less and reaches the services over a private per-workspace network at
 * the conventional hosts (db, redis). The connection env (DB_HOST, REDIS_HOST,
 * …) is read off the returned WorkerSidecars handle.
 */
class WorkerSidecarManager
{
    /** Label every workspace-owned Docker resource carries (orphan sweep). */
    public const WORKSPACE_LABEL = 'argos.workspace';

    /**
     * Start the enabled services of a workspace as long-lived sidecars on a
     * per-workspace network. Their data lives in named volumes (`<network>_<alias>`), so a stopped and restarted workspace
     * keeps its database. Containers are recreated on every start.
     */
    public function startForWorkspace(Workspace $workspace): WorkerSidecars
    {
        $profile = $workspace->repoProfile;
        $services = $profile->backingServices();
        if ($services === []) {
            return new WorkerSidecars;
        }

        $coordinates = app(BackingServiceResolver::class)->coordinates($profile);
        $network = $this->workspaceNetworkName($workspace);
        $label = ['--label', self::WORKSPACE_LABEL.'='.$workspace->slug];

        $this->stopWorkspaceContainers($workspace);
        if (! $this->networkExists($network)) {
            $this->mustRun(['docker', 'network', 'create', ...$label, $network], 30);
        }

        $containers = [];
        $env = [];

        try {
            foreach ($services as $service) {
                $coords = $coordinates[$service->value];
                $alias = $coords['host'];
                $container = $network.'-'.$alias;
                $cmd = [
                    'docker', 'run', '-d',
                    '--name', $container,
                    '--network', $network,
                    '--network-alias', $alias,
                    '--restart', 'unless-stopped',
                    '-v', $network.'_'.$alias.':'.$service->dataPath(),
                    ...$label,
                ];
                foreach ($service->containerEnv($coords) as $key => $value) {
                    $cmd[] = '-e';
                    $cmd[] = "{$key}={$value}";
                }
                $cmd[] = $service->image();

                $this->mustRun($cmd, 60);
                $containers[] = $container;
                $env = array_merge($env, $service->connectionEnv($coords));
            }

            $this->awaitReadiness($services, $containers);
        } catch (Throwable $e) {
            $this->stopWorkspaceContainers($workspace);

            throw $e;
        }

        return new WorkerSidecars($network, $env, $containers);
    }

    /** Remove a workspace's sidecar containers; volumes and network stay. */
    public function stopWorkspaceContainers(Workspace $workspace): void
    {
        $network = $this->workspaceNetworkName($workspace);
        foreach ($workspace->repoProfile->backingServices() as $service) {
            $alias = $service->defaultCoordinates()['host'];
            $this->runQuietly(['docker', 'rm', '-f', $network.'-'.$alias], 30);
        }
    }

    /**
     * Remove everything the workspace's sidecars left behind: containers,
     * data volumes and the network. Works by slug alone (teardown after the
     * workspace row is gone).
     */
    public function purgeWorkspace(string $slug): void
    {
        $network = 'ws_'.$slug.'_svc';
        foreach (BackingService::cases() as $service) {
            $alias = $service->defaultCoordinates()['host'];
            $this->runQuietly(['docker', 'rm', '-f', $network.'-'.$alias], 30);
            $this->runQuietly(['docker', 'volume', 'rm', '-f', $network.'_'.$alias], 30);
        }
        $this->runQuietly(['docker', 'network', 'rm', $network], 30);
    }

    public function workspaceNetworkName(Workspace $workspace): string
    {
        return 'ws_'.$workspace->slug.'_svc';
    }

    private function networkExists(string $network): bool
    {
        $process = $this->newProcess(['docker', 'network', 'inspect', $network]);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Poll each service's readiness probe until it passes or the startup
     * timeout elapses.
     *
     * @param  list<BackingService>  $services
     * @param  list<string>  $containers
     */
    private function awaitReadiness(array $services, array $containers): void
    {
        $timeout = (int) config('argos.worker.services.startup_timeout', 60);
        $deadline = $this->now() + $timeout;

        foreach ($services as $i => $service) {
            $probe = array_merge(['docker', 'exec', $containers[$i]], $service->readinessProbe());

            while (! $this->probeSucceeds($probe)) {
                if ($this->now() >= $deadline) {
                    throw new RuntimeException(
                        "Worker service '{$service->value}' not ready after {$timeout}s."
                    );
                }
                $this->sleep(2);
            }
        }
    }

    /**
     * @param  list<string>  $probe
     */
    private function probeSucceeds(array $probe): bool
    {
        $process = $this->newProcess($probe);
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (Throwable) {
            // exec itself failed (container not up yet) — treat as not-ready.
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $cmd
     */
    private function mustRun(array $cmd, int $timeout): void
    {
        $process = $this->newProcess($cmd);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'docker command failed (exit '.$process->getExitCode()."):\n".$process->getErrorOutput()
            );
        }
    }

    /**
     * @param  list<string>  $cmd
     */
    private function runQuietly(array $cmd, int $timeout): void
    {
        try {
            $process = $this->newProcess($cmd);
            $process->setTimeout($timeout);
            $process->run();
        } catch (Throwable) {
            // best-effort — container/network already gone, or docker unavailable
        }
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }

    protected function now(): float
    {
        return microtime(true);
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}

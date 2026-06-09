<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\BackingService;
use App\Models\Task;
use App\Services\Project\BackingServiceResolver;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Boots the backing services a repo profile enabled (MySQL, Redis) as ephemeral
 * sidecars for a worker phase run, then tears them down. The Manager owns the
 * Docker socket; the worker stays socket-less and reaches the services over a
 * private per-run network at the conventional hosts (db, redis).
 *
 * Only phases that run the test gate need them — see PHASES_NEEDING_SERVICES.
 * The connection env (DB_HOST, REDIS_HOST, …) ends up on the worker via
 * PhaseCommandBuilder, which reads it off the returned WorkerSidecars handle.
 */
class WorkerSidecarManager
{
    /** Phases whose worker runs the quality/test gate and may hit a DB. */
    private const PHASES_NEEDING_SERVICES = ['implement', 'respond'];

    /**
     * Start the enabled services for this run. Returns an empty handle when the
     * phase doesn't run tests or the profile enabled no services. On a partial
     * failure it tears down whatever started and rethrows.
     */
    public function start(Task $task, string $phase): WorkerSidecars
    {
        if (! in_array($phase, self::PHASES_NEEDING_SERVICES, true)) {
            return new WorkerSidecars;
        }

        $profile = $task->repoProfile;
        $services = $profile?->backingServices() ?? [];
        if ($profile === null || $services === []) {
            return new WorkerSidecars;
        }

        $coordinates = app(BackingServiceResolver::class)->coordinates($profile);

        $network = $this->networkName($task, $phase);
        $this->mustRun(['docker', 'network', 'create', $network], 30);

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
            // Never leak half-started sidecars when one fails to boot.
            $this->stop(new WorkerSidecars($network, $env, $containers));

            throw $e;
        }

        Log::channel('argos')->info('Worker sidecars started', [
            'task' => $task->name,
            'phase' => $phase,
            'network' => $network,
            'services' => array_map(static fn (BackingService $s): string => $s->value, $services),
        ]);

        return new WorkerSidecars($network, $env, $containers);
    }

    /**
     * Best-effort teardown — safe to call with an empty handle, and never
     * throws (it runs in the caller's finally block).
     */
    public function stop(WorkerSidecars $sidecars): void
    {
        if ($sidecars->network === null) {
            return;
        }

        foreach ($sidecars->containers as $container) {
            $this->runQuietly(['docker', 'rm', '-f', $container], 30);
        }
        $this->runQuietly(['docker', 'network', 'rm', $sidecars->network], 30);
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

    private function networkName(Task $task, string $phase): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $task->name));
        $base = trim($base, '-');

        return 'argos-run-'.($base !== '' ? $base : 'task').'-'.$phase;
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

<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Workspace;
use App\Services\Project\ProjectEnvironmentResolver;
use App\Services\Worker\WorkerSidecarManager;
use App\Services\Worker\WorkerSidecars;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Docker side of a workspace: its volumes and its long-lived container
 * (workspace daemon + OpenVSCode Server). Everything is addressed by the
 * workspace slug and labelled `argos.workspace=<slug>` for the orphan sweep.
 */
class WorkspaceContainerManager
{
    /** Same uid/gid as the worker images' `agent` user (see Dockerfile.compose). */
    private const AGENT_UID = '1000:1000';

    public function __construct(private readonly WorkspaceDaemonClient $daemon) {}

    /** Create both volumes and hand them to the agent user. Idempotent. */
    public function prepareVolumes(Workspace $workspace): void
    {
        foreach ([$workspace->volumeName(), $workspace->agentsVolumeName()] as $volume) {
            $this->mustRun(['docker', 'volume', 'create', '--label', $this->label($workspace), $volume], 30);
        }

        $this->mustRun([
            'docker', 'run', '--rm',
            '-v', $workspace->volumeName().':/workspace',
            '-v', $workspace->agentsVolumeName().':/agents',
            'alpine:3.20', 'chown', self::AGENT_UID, '/workspace', '/agents',
        ], 120);
    }

    /**
     * The `docker run` command for the workspace container.
     *
     * @return list<string>
     */
    public function runCommand(Workspace $workspace, string $image, WorkerSidecars $sidecars): array
    {
        $internal = (string) config('argos.workspaces.internal_url');
        $cmd = [
            'docker', 'run', '-d',
            '--name', $workspace->containerName(),
            '--hostname', $workspace->containerName(),
            '--network', (string) config('argos.workspaces.network', 'argos_ws'),
            '--restart', 'unless-stopped',
            '--label', $this->label($workspace),
            // The workspace's own limits (settings → Resources) win over the installation default.
            '--cpus', $workspace->cpu_limit !== null ? (string) (float) $workspace->cpu_limit : (string) config('argos.workspaces.cpus', '2'),
            '--memory', $workspace->memory_limit_mb !== null ? $workspace->memory_limit_mb.'m' : (string) config('argos.workspaces.memory', '4g'),
            '-v', $workspace->volumeName().':/workspace',
            '-v', $workspace->agentsVolumeName().':/home/agent/.claude-agents',
            '-v', 'composer_cache:/home/agent/.composer/cache',
            '-v', 'npm_cache:/home/agent/.npm',
            '-e', 'ARGOS_DAEMON_TOKEN='.$workspace->daemon_token,
            '-e', 'ARGOS_EVENTS_URL='.$internal.'/internal/workspaces/'.$workspace->id.'/events',
            '-e', 'ARGOS_REPO_CREDENTIAL_URL='.$internal.'/internal/workspaces/'.$workspace->id.'/repo-credential',
            '-e', 'ARGOS_DAEMON_PORT='.(int) config('argos.workspaces.daemon_port', 7777),
            '-e', 'ARGOS_EDITOR_PORT='.(int) config('argos.workspaces.editor_port', 3000),
            // Dummy key so the project's Laravel boots for artisan/tests; the
            // workspace volume holds nothing this key protects.
            '-e', 'APP_KEY=base64:QXJnb3NXb3JrZXJEdW1teUtleU5vU2VjcmV0c0hlcmU=',
        ];

        foreach ($sidecars->env as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = "{$key}={$value}";
        }
        foreach (app(ProjectEnvironmentResolver::class)->resolve($workspace) as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = "{$key}={$value}";
        }

        $cmd[] = $image;

        return $cmd;
    }

    /** Start the container (recreated from the image on every start). */
    public function run(Workspace $workspace, string $image, WorkerSidecars $sidecars): void
    {
        $this->remove($workspace);
        $this->mustRun($this->runCommand($workspace, $image, $sidecars), 60);

        if ($sidecars->network !== null) {
            $this->mustRun(['docker', 'network', 'connect', $sidecars->network, $workspace->containerName()], 30);
        }
    }

    public function stop(Workspace $workspace, int $graceSeconds = 20): void
    {
        $this->runQuietly(['docker', 'stop', '-t', (string) $graceSeconds, $workspace->containerName()], $graceSeconds + 15);
    }

    public function remove(Workspace $workspace): void
    {
        $this->removeBySlug($workspace->slug);
    }

    public function removeBySlug(string $slug): void
    {
        $this->runQuietly(['docker', 'rm', '-f', 'argos-ws-'.$slug], 30);
    }

    public function removeVolumesBySlug(string $slug): void
    {
        $this->runQuietly(['docker', 'volume', 'rm', '-f', 'ws_'.$slug, 'ws_'.$slug.'_agents'], 60);
    }

    public function isRunning(Workspace $workspace): bool
    {
        $process = $this->newProcess(['docker', 'inspect', '-f', '{{.State.Running}}', $workspace->containerName()]);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'true';
    }

    /**
     * Slugs of all workspace containers Docker knows about.
     *
     * @return list<string>
     */
    public function containerSlugs(): array
    {
        $process = $this->newProcess([
            'docker', 'ps', '-a',
            '--filter', 'label='.WorkerSidecarManager::WORKSPACE_LABEL,
            '--format', '{{.Label "'.WorkerSidecarManager::WORKSPACE_LABEL.'"}}',
        ]);
        $process->setTimeout(30);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode("\n", $process->getOutput())))));
    }

    /**
     * Poll the daemon's /health until it answers.
     *
     * @return array<string, mixed> the health payload
     */
    public function waitForHealth(Workspace $workspace, int $timeoutSeconds = 90): array
    {
        $deadline = $this->now() + $timeoutSeconds;
        $last = 'no answer';

        while ($this->now() < $deadline) {
            try {
                return $this->daemon->for($workspace, 5)->health();
            } catch (DaemonTransportException $e) {
                $last = $e->getMessage();
            }
            $this->sleep(2);
        }

        throw new RuntimeException("Workspace daemon not healthy after {$timeoutSeconds}s: {$last}");
    }

    private function label(Workspace $workspace): string
    {
        return WorkerSidecarManager::WORKSPACE_LABEL.'='.$workspace->slug;
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
            throw new RuntimeException('docker '.($cmd[1] ?? '').' failed (exit '.$process->getExitCode()."):\n".$process->getErrorOutput());
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
        } catch (\Throwable) {
            // best-effort — already gone or docker unavailable
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

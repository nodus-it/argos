<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\DemoStatus;
use App\Models\Demo;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\GitProvider\GitServiceFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Deploys an ephemeral live demo for a task after a successful implement run.
 *
 * The implemented code already lives in the task workspace volume
 * (`task_ws_{id}`); rather than checking it out again, the deployer MOUNTS that
 * volume into the entry service of the repo-supplied `.argos/demo.compose.yml`,
 * runs the configured `commands` inside the container, and publishes a Traefik
 * file-provider route so the demo is reachable under its own subdomain.
 *
 * Manager-side only (needs the docker socket) — analogous to WorkerImageBuilder.
 */
class DemoDeployer
{
    /** Per-`docker compose` step timeout (seconds). */
    public int $composeTimeoutSeconds = 300;

    /** Per in-container command timeout (seconds). */
    public int $commandTimeoutSeconds = 600;

    public function __construct(private readonly GitServiceFactory $gitFactory) {}

    /**
     * Build (or rebuild) the live demo for a task. Always replaces a previous
     * demo of the same task. Returns the persisted Demo row (live or failed).
     */
    public function deploy(Task $task): Demo
    {
        $profile = $task->repoProfile;
        if ($profile === null) {
            throw new RuntimeException("Task '{$task->name}' has no repo profile — cannot deploy a demo.");
        }

        $slug = $this->demoSlug($task);
        $project = $slug;

        // A task has exactly one current demo — tear the old one down first so
        // a re-implement cleanly replaces it (containers, volumes, route).
        $this->teardownExisting($task, $slug);

        $demo = Demo::query()->create([
            'task_id' => $task->id,
            'status' => DemoStatus::Building,
            'compose_project' => $project,
            'url' => null,
            'ttl_until' => now()->addHours((int) config('argos.preview.ttl_hours', 24)),
            'build_log' => null,
        ]);

        $log = '';
        try {
            [$composeYaml, $settings] = $this->readContract($profile);
            $entry = $this->parseEntry($settings);

            $workDir = $this->prepareWorkDir($slug, $composeYaml, $this->buildOverrideYaml($task, $slug, $entry));

            $log .= $this->composeUp($project, $workDir);
            $log .= $this->runCommands($project, $entry['service'], $settings['commands'] ?? []);
            $log .= $this->probeHealth($project, $entry, $settings['health'] ?? null);

            $url = $this->writeTraefikRoute($slug, $entry['port']);

            $demo->forceFill([
                'status' => DemoStatus::Live,
                'url' => $url,
                'build_log' => $this->truncateLog($log),
            ])->save();
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Demo deploy failed', [
                'task' => $task->name,
                'demo' => $demo->id,
                'error' => $e->getMessage(),
            ]);

            // Best-effort cleanup so a failed build leaves nothing running.
            $this->teardownExisting($task, $slug);

            $demo->forceFill([
                'status' => DemoStatus::Failed,
                'build_log' => $this->truncateLog($log."\n\n[error] ".$e->getMessage()),
            ])->save();
        }

        return $demo->refresh();
    }

    /**
     * Stop and remove a task's running demo (containers + volumes + route).
     * Safe to call when nothing is running.
     */
    public function teardown(Task $task): void
    {
        $this->teardownExisting($task, $this->demoSlug($task));
    }

    private function teardownExisting(Task $task, string $slug): void
    {
        $down = $this->newProcess(['docker', 'compose', '-p', $slug, 'down', '-v', '--remove-orphans']);
        $down->setTimeout($this->composeTimeoutSeconds);
        try {
            $down->run();
        } catch (Throwable) {
            // best-effort — nothing to tear down, or docker unavailable in tests
        }

        $route = $this->routeFilePath($slug);
        if (is_file($route)) {
            @unlink($route);
        }
    }

    /**
     * Fetch both contract files from the provider at the base branch.
     *
     * @return array{0: string, 1: array<string, mixed>} [composeYaml, settings]
     */
    private function readContract(RepoProfile $profile): array
    {
        $service = $this->gitFactory->fromRepoProfile($profile);
        $ownerRepo = $profile->getOwnerRepo();
        $ref = $profile->default_branch;

        $composeYaml = $service->getFileContents($ownerRepo, DemoConfigLocator::COMPOSE_PATH, $ref);
        $settingsYaml = $service->getFileContents($ownerRepo, DemoConfigLocator::SETTINGS_PATH, $ref);

        if ($composeYaml === null || $settingsYaml === null) {
            throw new RuntimeException(
                'Demo contract incomplete: '.DemoConfigLocator::COMPOSE_PATH.' and '
                .DemoConfigLocator::SETTINGS_PATH.' must both exist at '.$ref.'.'
            );
        }

        $settings = Yaml::parse($settingsYaml);
        if (! is_array($settings)) {
            throw new RuntimeException(DemoConfigLocator::SETTINGS_PATH.' is not valid YAML.');
        }

        return [$composeYaml, $settings];
    }

    /**
     * Validate + normalise the `entry` block of demo.yml.
     *
     * @param  array<string, mixed>  $settings
     * @return array{service: string, port: int, workspace_mount: string}
     */
    private function parseEntry(array $settings): array
    {
        $service = $settings['entry']['service'] ?? null;
        $port = $settings['entry']['port'] ?? null;
        $mount = $settings['workspace_mount'] ?? null;

        if (! is_string($service) || $service === '') {
            throw new RuntimeException('demo.yml: entry.service is required.');
        }
        if (! is_int($port) && ! (is_string($port) && ctype_digit($port))) {
            throw new RuntimeException('demo.yml: entry.port must be an integer.');
        }
        if (! is_string($mount) || $mount === '') {
            throw new RuntimeException('demo.yml: workspace_mount is required.');
        }

        return ['service' => $service, 'port' => (int) $port, 'workspace_mount' => $mount];
    }

    /**
     * Generate the per-task compose override: mounts the task workspace volume
     * into the entry service, joins the Traefik edge network under a unique
     * alias (so the file-provider route can target it by DNS), and caps
     * resources. Deliberately carries NO Traefik labels — routing is done via
     * the file provider, not the docker provider.
     *
     * @param  array{service: string, port: int, workspace_mount: string}  $entry
     */
    public function buildOverrideYaml(Task $task, string $slug, array $entry): string
    {
        $network = (string) config('argos.preview.network', 'argos_edge');

        $override = [
            'services' => [
                $entry['service'] => [
                    'volumes' => [
                        $task->volumeName().':'.$entry['workspace_mount'],
                    ],
                    'networks' => [
                        $network => [
                            'aliases' => [$slug],
                        ],
                    ],
                    'deploy' => [
                        'resources' => [
                            'limits' => [
                                'cpus' => (string) config('argos.docker.cpu_limit', '2'),
                                'memory' => (string) config('argos.docker.memory_limit', '4g'),
                            ],
                        ],
                    ],
                ],
            ],
            'networks' => [
                $network => [
                    'external' => true,
                ],
            ],
        ];

        return Yaml::dump($override, 6, 2);
    }

    private function prepareWorkDir(string $slug, string $composeYaml, string $overrideYaml): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/').'/argos-demo-'.$slug;
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create demo work dir: {$dir}");
        }

        file_put_contents($dir.'/demo.compose.yml', $composeYaml);
        file_put_contents($dir.'/override.yml', $overrideYaml);

        return $dir;
    }

    private function composeUp(string $project, string $workDir): string
    {
        $process = $this->newProcess([
            'docker', 'compose',
            '-p', $project,
            '-f', $workDir.'/demo.compose.yml',
            '-f', $workDir.'/override.yml',
            'up', '-d', '--remove-orphans',
        ]);
        $process->setTimeout($this->composeTimeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("compose up failed (exit {$process->getExitCode()}):\n".$process->getErrorOutput());
        }

        return "[compose up]\n".$process->getOutput().$process->getErrorOutput()."\n";
    }

    /**
     * Run the configured lifecycle commands, in order, inside the entry
     * container. Stops at the first failure.
     *
     * @param  array<int, mixed>  $commands
     */
    private function runCommands(string $project, string $service, array $commands): string
    {
        $log = '';
        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '') {
                continue;
            }

            $process = $this->newProcess([
                'docker', 'compose', '-p', $project,
                'exec', '-T', $service,
                'sh', '-c', $command,
            ]);
            $process->setTimeout($this->commandTimeoutSeconds);
            $process->run();

            $log .= "[exec] {$command}\n".$process->getOutput().$process->getErrorOutput()."\n";

            if (! $process->isSuccessful()) {
                throw new RuntimeException("Demo command failed (exit {$process->getExitCode()}): {$command}");
            }
        }

        return $log;
    }

    /**
     * Best-effort readiness probe. When `health.path` is set, poll the entry
     * service from inside its own container (curl/wget) until it answers or the
     * timeout elapses. If neither tool exists in the image the probe is skipped
     * (we can't verify, so we don't block the demo on it).
     *
     * @param  array{service: string, port: int, workspace_mount: string}  $entry
     * @param  array<string, mixed>|null  $health
     */
    private function probeHealth(string $project, array $entry, ?array $health): string
    {
        $path = $health['path'] ?? null;
        if (! is_string($path) || $path === '') {
            return '';
        }

        $timeout = (int) ($health['timeout'] ?? 90);
        $deadline = max(1, $timeout);
        $url = 'http://localhost:'.$entry['port'].$path;
        $script = 'if command -v curl >/dev/null 2>&1; then curl -fsS '.escapeshellarg($url).' >/dev/null;'
            .' elif command -v wget >/dev/null 2>&1; then wget -qO- '.escapeshellarg($url).' >/dev/null;'
            .' else exit 0; fi';

        $elapsed = 0;
        $lastErr = '';
        while ($elapsed <= $deadline) {
            $process = $this->newProcess([
                'docker', 'compose', '-p', $project,
                'exec', '-T', $entry['service'],
                'sh', '-c', $script,
            ]);
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful()) {
                return "[health] ready after {$elapsed}s ({$url})\n";
            }

            $lastErr = $process->getErrorOutput();
            $this->sleep(3);
            $elapsed += 3;
        }

        throw new RuntimeException("Demo health check failed after {$timeout}s ({$url}): {$lastErr}");
    }

    /**
     * Write the Traefik file-provider route for this demo into the shared dir
     * and return the public URL. Traefik resolves the `{slug}` host alias on the
     * edge network to the entry container.
     */
    public function writeTraefikRoute(string $slug, int $port): string
    {
        $host = $slug.'.'.config('argos.preview.base_domain', '127.0.0.1.nip.io');

        $route = [
            'http' => [
                'routers' => [
                    $slug => [
                        'rule' => "Host(`{$host}`)",
                        'entryPoints' => ['web'],
                        'service' => $slug,
                    ],
                ],
                'services' => [
                    $slug => [
                        'loadBalancer' => [
                            'servers' => [
                                ['url' => "http://{$slug}:{$port}"],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $dir = $this->traefikDir();
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Traefik dynamic-config dir not writable: {$dir}");
        }
        file_put_contents($this->routeFilePath($slug), Yaml::dump($route, 8, 2));

        return $this->demoUrl($host);
    }

    /** Public URL for a demo host, appending the external port unless it is 80/443. */
    private function demoUrl(string $host): string
    {
        $scheme = (string) config('argos.preview.scheme', 'http');
        $port = (int) config('argos.preview.port', 8080);

        $needsPort = ! in_array($port, [80, 443], true);

        return $scheme.'://'.$host.($needsPort ? ':'.$port : '');
    }

    private function routeFilePath(string $slug): string
    {
        return rtrim($this->traefikDir(), '/').'/'.$slug.'.yml';
    }

    private function traefikDir(): string
    {
        return (string) config('argos.preview.traefik_dir', '/data/traefik');
    }

    /**
     * DNS-safe demo slug derived from the task name. Used identically as the
     * compose project name, the edge-network alias, the Traefik router/service
     * name, and the subdomain label — so they always line up.
     */
    public function demoSlug(Task $task): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $task->name));
        $base = trim($base, '-');

        return 'demo-'.($base !== '' ? $base : 'task');
    }

    /** Truncate an overly long build log to keep the DB row reasonable (head+tail). */
    private function truncateLog(string $log): string
    {
        $max = 200_000;
        if (strlen($log) <= $max) {
            return $log;
        }

        $head = substr($log, 0, 50_000);
        $tail = substr($log, -150_000);

        return $head."\n\n... [log truncated] ...\n\n".$tail;
    }

    /** Wraps sleep() so tests can stub it (health-probe retry backoff). */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}

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

    /** Token in the bundled default compose that the runtime image tag replaces. */
    private const DEMO_IMAGE_PLACEHOLDER = '__ARGOS_DEMO_IMAGE__';

    public function __construct(
        private readonly GitServiceFactory $gitFactory,
        private readonly DemoImageBuilder $imageBuilder,
    ) {}

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
        $this->teardownBySlug($slug);

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
            // Pull external commits a user pushed to the feature branch into the
            // volume first, so the demo reflects the current remote state (I2).
            // No-op on a dirty tree — see DemoWorkspaceSync.
            app(DemoWorkspaceSync::class)->syncToRemote($task);

            [$composeYaml, $settings] = $this->readContract($profile);

            // Resolve the built-in runtime-image placeholder to the content-
            // hashed, build-on-demand tag whenever a contract references it. The
            // bundled default always does; a repo contract may opt into the
            // built-in runtime by keeping the placeholder and only overriding
            // settings/commands. Contracts that ship their own image simply
            // omit the token, so this is a no-op for them.
            if (str_contains($composeYaml, self::DEMO_IMAGE_PLACEHOLDER)) {
                $composeYaml = str_replace(self::DEMO_IMAGE_PLACEHOLDER, $this->imageBuilder->ensure(), $composeYaml);
            }

            $entry = $this->parseEntry($settings);

            $router = app(TraefikRouter::class);
            $overrideYaml = app(DemoComposeBuilder::class)
                ->buildOverrideYaml($task, $slug, $entry, $router->urlForSlug($slug));
            $workDir = $this->prepareWorkDir($slug, $composeYaml, $overrideYaml);

            $log .= $this->enforceConcurrencyCap($task);
            $log .= $this->composeUp($project, $workDir);
            $log .= $this->runCommands($project, $entry['service'], $settings['commands'] ?? []);
            $log .= $this->probeHealth($project, $entry, $settings['health'] ?? null);

            $url = $router->writeRoute(
                $slug,
                $entry['port'],
                $task->effectiveDemoAccessMode(),
                $task->demo_basic_password,
            );

            $demo->forceFill([
                'status' => DemoStatus::Live,
                'url' => $url,
                'port' => $entry['port'],
                'build_log' => $this->truncateLog($log),
            ])->save();
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Demo deploy failed', [
                'task' => $task->name,
                'demo' => $demo->id,
                'error' => $e->getMessage(),
            ]);

            // Best-effort cleanup so a failed build leaves nothing running.
            $this->teardownBySlug($slug);

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
        $this->teardownBySlug($this->demoSlug($task));
    }

    /**
     * Tear a demo stack down by its slug alone — no Task row required, so this
     * still works after the task (and its demo row) has been deleted. The slug
     * is derived deterministically from the task name (demoSlug), so a deleted
     * task's stack stays addressable.
     */
    public function teardownBySlug(string $slug): void
    {
        $down = $this->newProcess(['docker', 'compose', '-p', $slug, 'down', '-v', '--remove-orphans']);
        $down->setTimeout($this->composeTimeoutSeconds);
        try {
            $down->run();
        } catch (Throwable) {
            // best-effort — nothing to tear down, or docker unavailable in tests
        }

        app(TraefikRouter::class)->removeRoute($slug);
    }

    /**
     * Honour preview.max_concurrent: if running demos of OTHER tasks would push
     * the total over the cap (counting the one we're about to start), evict the
     * oldest ones until there is room. Evictions are logged — never silent.
     */
    private function enforceConcurrencyCap(Task $current): string
    {
        $max = (int) config('argos.preview.max_concurrent', 10);
        if ($max <= 0) {
            return '';
        }

        $active = Demo::query()
            ->whereIn('status', [DemoStatus::Building->value, DemoStatus::Live->value])
            ->where('task_id', '!=', $current->id)
            ->orderBy('created_at')
            ->get();

        // Leave one slot for the demo we're starting.
        $overflow = $active->count() - ($max - 1);
        if ($overflow <= 0) {
            return '';
        }

        $log = '';
        foreach ($active->take($overflow) as $demo) {
            $task = $demo->task;
            if ($task !== null) {
                $this->teardownBySlug($this->demoSlug($task));
            }
            $demo->update(['status' => DemoStatus::Stopped, 'url' => null]);

            $log .= "[cap] evicted demo {$demo->id} (task {$demo->task_id}) to stay under max_concurrent={$max}\n";
            Log::channel('argos')->info('Evicted demo to honour concurrency cap', [
                'demo' => $demo->id,
                'task' => $demo->task_id,
                'max' => $max,
            ]);
        }

        return $log;
    }

    /**
     * Fetch both contract files from the provider at the base branch, or fall
     * back to the bundled default when the repo ships none.
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

        if ($composeYaml === null && $settingsYaml === null) {
            // No contract at all → built-in default runtime, unified with the
            // project's backing-service config (creds + Redis toggle).
            [$composeYaml, $settingsYaml] = $this->defaultContract($profile);
        } elseif ($composeYaml === null || $settingsYaml === null) {
            // A half-written contract is a mistake the author must see, not a
            // silent fall-through to the generic demo.
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
     * Build the bundled default Laravel demo contract (compose + settings),
     * unified with the profile's backing-service config (DB credentials + an
     * optional Redis service).
     *
     * @return array{0: string, 1: string} [composeYaml, settingsYaml]
     */
    private function defaultContract(RepoProfile $profile): array
    {
        return app(DemoContractBuilder::class)->buildDefault($profile);
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
     * Re-write the live demo's route for a task after its access mode (or basic
     * password) changed — applies the new protection without a full redeploy.
     * No-op when the task has no live demo with a known entry port.
     */
    public function applyAccessMode(Task $task): void
    {
        $demo = $task->currentDemo();
        if ($demo === null || $demo->status !== DemoStatus::Live) {
            return;
        }

        $slug = $this->demoSlug($task);
        $router = app(TraefikRouter::class);

        // Demos deployed before the `port` column existed have a null port —
        // recover it from the live route file so the toggle still works.
        $port = $demo->port ?? $router->existingRoutePort($slug);
        if ($port === null) {
            return;
        }

        if ($demo->port === null) {
            $demo->forceFill(['port' => $port])->save();
        }

        $router->writeRoute(
            $slug,
            $port,
            $task->effectiveDemoAccessMode(),
            $task->demo_basic_password,
        );
    }

    /**
     * DNS-safe demo slug derived from the task name. Used identically as the
     * compose project name, the edge-network alias, the Traefik router/service
     * name, and the subdomain label — so they always line up.
     */
    public function demoSlug(Task $task): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $task->slug));
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

<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\DemoStatus;
use App\Enums\TaskKind;
use App\Models\Demo;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\GitProvider\GitServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Deploys an ephemeral live demo (preview) for a workspace.
 *
 * The code already lives in the workspace volume; rather than checking it out
 * again, the deployer MOUNTS that volume into the entry service of the repo-supplied `.argos/demo.compose.yml`,
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
     * Build (or rebuild) the live demo for a target. Always replaces
     * a previous demo of the same target. Returns the persisted Demo row (live
     * or failed).
     */
    public function deploy(DemoTarget $target): Demo
    {
        $workspace = $target->demoWorkspace();
        $slug = $target->demoSlug();
        $project = $slug;

        // A target has exactly one current demo — tear the old one down first so
        // a rebuild cleanly replaces it (containers, volumes, route).
        $this->teardownBySlug($slug);

        $demo = Demo::query()->create([
            ...$this->ownerColumns($target),
            'status' => DemoStatus::Building,
            'compose_project' => $project,
            'url' => null,
            'ttl_until' => now()->addHours((int) config('argos.preview.ttl_hours', 24)),
            'build_log' => null,
        ]);

        $log = '';
        try {
            [$composeYaml, $settings] = $this->readContract($workspace, $this->contractRef($target));

            // Resolve the built-in runtime-image placeholder to the content-
            // hashed, build-on-demand tag whenever a contract references it. The
            // bundled default always does; a repo contract may opt into the
            // built-in runtime by keeping the placeholder and only overriding
            // settings/commands. Contracts that ship their own image simply
            // omit the token, so this is a no-op for them.
            if (str_contains($composeYaml, self::DEMO_IMAGE_PLACEHOLDER)) {
                $image = $this->imageBuilder->ensure($this->imageBuilder->phpVersionFor($workspace));
                $composeYaml = str_replace(self::DEMO_IMAGE_PLACEHOLDER, $image, $composeYaml);
            }

            // Repository content, started over the host's Docker socket: only a
            // safe subset of compose passes, hardened (see DemoComposeGuard).
            $composeYaml = app(DemoComposeGuard::class)->harden($composeYaml);

            $entry = $this->parseEntry($settings);

            $router = app(TraefikRouter::class);
            $overrideYaml = app(DemoComposeBuilder::class)
                ->buildOverrideYaml($target, $slug, $entry, $router->urlForSlug($slug));
            $workDir = $this->prepareWorkDir($slug, $composeYaml, $overrideYaml);

            $log .= $this->enforceConcurrencyCap($target);
            $log .= $this->composeUp($project, $workDir);
            $this->runCommands($project, $entry['service'], $settings['commands'] ?? [], $target->demoExecUser(), $log);
            $log .= $this->probeData($project, $entry['service'], $target->demoExecUser());
            $log .= $this->probeHealth($project, $entry, $settings['health'] ?? null);

            $url = $router->writeRoute($slug, $entry['port']);

            $demo->forceFill([
                'status' => DemoStatus::Live,
                'url' => $url,
                'port' => $entry['port'],
                'build_log' => $this->truncateLog($log),
            ])->save();
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Demo deploy failed', [
                'target' => $slug,
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
     * Stop and remove a target's running demo (containers + volumes + route).
     * Safe to call when nothing is running.
     */
    public function teardown(DemoTarget $target): void
    {
        $this->teardownBySlug($target->demoSlug());
    }

    /**
     * Re-run the contract's setup commands in the running demo — after a
     * migration, a composer/npm change or a build-config edit — without a
     * teardown. Appends the output to the demo's build log.
     */
    public function rerunCommands(DemoTarget $target): Demo
    {
        $demo = $this->currentDemoOf($target);
        if ($demo === null || $demo->status !== DemoStatus::Live) {
            throw new RuntimeException('No live demo to re-run the setup commands in.');
        }

        $workspace = $target->demoWorkspace();

        [, $settings] = $this->readContract($workspace, $this->contractRef($target));
        $entry = $this->parseEntry($settings);

        $log = "\n[rerun ".now()->toIso8601String()."]\n";
        try {
            $this->runCommands($target->demoSlug(), $entry['service'], $settings['commands'] ?? [], $target->demoExecUser(), $log);
        } catch (Throwable $e) {
            $log .= '[error] '.$e->getMessage()."\n";
        }

        $demo->forceFill(['build_log' => $this->truncateLog(($demo->build_log ?? '').$log)])->save();

        return $demo->refresh();
    }

    /**
     * Columns tying a demo row to its target.
     *
     * @return array<string, string|null>
     */
    private function ownerColumns(DemoTarget $target): array
    {
        return [
            'workspace_id' => $target->demoWorkspace()->id,
            'task_id' => $target instanceof Task ? $target->id : null,
        ];
    }

    private function currentDemoOf(DemoTarget $target): ?Demo
    {
        return Demo::query()->where($this->ownerColumns($target))->latest()->first();
    }

    /**
     * Tear a demo stack down by its slug alone, so this still works after the
     * target (and its demo row) has been deleted.
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
     * Honour the workspace's preview limit and the platform's
     * preview.max_concurrent: if running demos of OTHER targets would push a
     * total over its cap (counting the one we're about to start), evict the
     * oldest ones until there is room. Evictions are logged — never silent.
     */
    private function enforceConcurrencyCap(DemoTarget $current): string
    {
        $own = $this->ownerColumns($current);
        $log = $this->evictOverflow(
            (int) $current->demoWorkspace()->max_previews,
            fn ($q) => $q->where('workspace_id', $own['workspace_id']),
            $own['task_id'],
        );

        return $log.$this->evictOverflow((int) config('argos.preview.max_concurrent', 10), fn ($q) => $q, $own['task_id']);
    }

    /** @param  callable(Builder<Demo>): mixed  $scope */
    private function evictOverflow(int $max, callable $scope, ?string $exceptTaskId): string
    {
        if ($max <= 0) {
            return '';
        }

        $active = Demo::query()
            ->whereIn('status', [DemoStatus::Building->value, DemoStatus::Live->value])
            ->where($scope)
            ->when($exceptTaskId !== null, fn ($q) => $q->where('task_id', '!=', $exceptTaskId))
            ->orderBy('created_at')
            ->get();

        // Leave one slot for the demo we're starting.
        $overflow = $active->count() - ($max - 1);
        if ($overflow <= 0) {
            return '';
        }

        $log = '';
        foreach ($active->take($overflow) as $demo) {
            $target = $demo->target();
            if ($target !== null) {
                $this->teardownBySlug($target->demoSlug());
            }
            $demo->update(['status' => DemoStatus::Stopped, 'url' => null]);

            $log .= "[cap] evicted demo {$demo->id} (task {$demo->task_id}) to stay under {$max} previews\n";
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
    private function readContract(Workspace $workspace, string $ref): array
    {
        $service = $this->gitFactory->forWorkspace($workspace);
        $ownerRepo = $workspace->getOwnerRepo();

        $composeYaml = $service->getFileContents($ownerRepo, DemoConfigLocator::COMPOSE_PATH, $ref);
        $settingsYaml = $service->getFileContents($ownerRepo, DemoConfigLocator::SETTINGS_PATH, $ref);

        if ($composeYaml === null && $settingsYaml === null) {
            // No contract at all → built-in default runtime, unified with the
            // project's backing-service config (creds + Redis toggle).
            [$composeYaml, $settingsYaml] = $this->defaultContract($workspace);
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
     * The branch the contract is read from: a setup task tests its own
     * branch; everything else uses the workspace's setup (an unmerged setup
     * branch, else the default branch).
     */
    private function contractRef(DemoTarget $target): string
    {
        if ($target instanceof Task && $target->kind === TaskKind::Setup && $target->branch !== null) {
            return $target->branch;
        }

        return $target->demoWorkspace()->setupRef();
    }

    /**
     * Build the bundled default Laravel demo contract (compose + settings),
     * unified with the profile's backing-service config (DB credentials + an
     * optional Redis service).
     *
     * @return array{0: string, 1: string} [composeYaml, settingsYaml]
     */
    private function defaultContract(Workspace $workspace): array
    {
        return app(DemoContractBuilder::class)->buildDefault($workspace);
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
     * container. Stops at the first failure; each output lands in `$log`
     * as it runs, so the failing command's output survives the exception.
     *
     * @param  array<int, mixed>  $commands
     */
    private function runCommands(string $project, string $service, array $commands, ?string $user, string &$log): void
    {
        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '') {
                continue;
            }

            $exec = ['docker', 'compose', '-p', $project, 'exec', '-T'];
            if ($user !== null) {
                // HOME must be writable for npm/composer caches of a non-root user.
                $exec = [...$exec, '--user', $user, '-e', 'HOME=/tmp'];
            }
            $process = $this->newProcess([...$exec, $service, 'sh', '-c', $command]);
            $process->setTimeout($this->commandTimeoutSeconds);
            $process->run();

            $log .= "[exec] {$command}\n".$process->getOutput().$process->getErrorOutput()."\n";

            if (! $process->isSuccessful()) {
                throw new RuntimeException("Demo command failed (exit {$process->getExitCode()}): {$command}");
            }
        }
    }

    /**
     * A warning line when a Laravel demo has no rows outside `migrations`; other
     * stacks, or an artisan that cannot tell, pass silently.
     */
    private function probeData(string $project, string $service, ?string $user): string
    {
        $exec = ['docker', 'compose', '-p', $project, 'exec', '-T', ...($user !== null ? ['--user', $user, '-e', 'HOME=/tmp'] : [])];
        $process = $this->newProcess([...$exec, $service, 'sh', '-c', 'test -f artisan && php artisan db:show --counts --json 2>/dev/null']);
        $process->setTimeout(60);
        $process->run();

        $tables = json_decode($process->getOutput(), true)['tables'] ?? null;
        if (! $process->isSuccessful() || ! is_array($tables)) {
            return '';
        }
        $rows = array_sum(array_map(fn (mixed $t): int => is_array($t) && ($t['table'] ?? '') !== 'migrations' ? (int) ($t['rows'] ?? 0) : 0, $tables));

        return $rows > 0 ? '' : "[warn] The demo database has no data outside `migrations` — does a seeder run?\n";
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

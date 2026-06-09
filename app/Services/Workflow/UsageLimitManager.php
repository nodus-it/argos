<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\PhaseRun;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Owns everything around the Anthropic usage limit and post-crash cost
 * recovery: salvaging cost/token counters from the volume when the worker
 * crashed before emitting its result line, reading the worker's
 * usage_limit.env reset timestamp, and the application cache that the banner
 * component and RunPhaseJob both read to hold work back while a limit is
 * active. Split out of PhaseRunner so the runner owns only run orchestration.
 */
class UsageLimitManager
{
    /**
     * The cache key the usage-limit signal lives under. UsageLimitBanner and
     * RunPhaseJob read it via current()/isActive() — they no longer touch the
     * cache directly.
     */
    public const CACHE_KEY = 'usage_limit';

    public function __construct(
        private readonly WorkerVolumeReader $volumeReader,
    ) {}

    /**
     * Defensive cost recovery: if the worker crashed before emitting its result
     * line, the per-iteration Claude `*.result.json` files are still on the
     * volume, so cost/token counters can be salvaged from there.
     */
    public function recoverUsageFromVolume(Task $task, PhaseRun $phaseRun, string $phase): void
    {
        $iteration = (int) $phaseRun->iteration;
        if ($iteration <= 0) {
            return;
        }

        $script = sprintf(
            'set -e; for f in /workspace/.agent/logs/%s.%d.result.json '.
            '/workspace/.agent/logs/%s.%d.fix*.result.json; do [ -f "$f" ] && cat "$f"; done',
            $phase,
            $iteration,
            $phase,
            $iteration,
        );

        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace:ro',
            'alpine',
            'sh', '-c', $script,
        ]);

        try {
            $process->setTimeout(15);
            $process->run();
            if (! $process->isSuccessful()) {
                return;
            }
            $output = $process->getOutput();
        } catch (\Throwable $e) {
            // Recovery is best-effort — missing docker, mock gaps in tests,
            // or any other transient issue should not break the phase run.
            Log::channel('argos')->debug('Cost recovery skipped', ['error' => $e->getMessage()]);

            return;
        }

        if ($output === '') {
            return;
        }

        $totalCost = 0.0;
        $totalIn = 0;
        $totalOut = 0;
        $found = false;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }
            $found = true;
            $totalCost += (float) ($decoded['total_cost_usd'] ?? 0);
            $totalIn += (int) ($decoded['usage']['input_tokens'] ?? 0);
            $totalOut += (int) ($decoded['usage']['output_tokens'] ?? 0);
        }

        if (! $found) {
            return;
        }

        $phaseRun->update([
            'cost_usd' => $totalCost,
            'input_tokens' => $totalIn,
            'output_tokens' => $totalOut,
        ]);
    }

    /**
     * Read the usage_limit.env file the worker writes when it detects a rate
     * limit. Returns the reset timestamp if the file contained one, otherwise
     * null.
     */
    public function readResetAt(Task $task): ?Carbon
    {
        $content = $this->volumeReader->readFile(
            $task->volumeName(),
            '/workspace/.agent/runtime/usage_limit.env'
        );

        if ($content === null) {
            return null;
        }

        if (preg_match('/USAGE_LIMIT_RESET_AT=([^\s]+)/', $content, $m)) {
            try {
                return Carbon::parse(trim($m[1]));
            } catch (\Throwable) {
                // malformed timestamp — ignore
            }
        }

        return null;
    }

    /**
     * Persist the active usage-limit signal in the application cache.
     */
    public function store(?Carbon $resetAt): void
    {
        $data = [
            'active' => true,
            'reset_at' => $resetAt?->toIso8601String(),
            'detected_at' => now()->toIso8601String(),
        ];

        $ttl = ($resetAt !== null && $resetAt->isFuture())
            ? $resetAt->clone()->addMinutes(5)
            : now()->addHours(2);

        Cache::put(self::CACHE_KEY, $data, $ttl);

        Log::channel('argos')->warning('Usage limit detected and stored', [
            'reset_at' => $data['reset_at'],
        ]);
    }

    /**
     * The cached usage-limit signal, or null when no limit is active.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $data = Cache::get(self::CACHE_KEY);

        return is_array($data) ? $data : null;
    }

    /**
     * Whether a usage limit is currently flagged active.
     */
    public function isActive(): bool
    {
        $data = $this->current();

        return $data !== null && ($data['active'] ?? false);
    }

    /**
     * Clear the usage-limit signal (banner dismiss / expiry).
     */
    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Seconds to wait before retrying a held-back phase: until the reset
     * timestamp (min 60s) when known, otherwise a 15-minute fallback.
     */
    public function retryDelaySeconds(): int
    {
        $data = $this->current();
        $resetAt = isset($data['reset_at']) ? Carbon::parse($data['reset_at']) : null;

        return ($resetAt !== null && $resetAt->isFuture())
            ? max(60, (int) now()->diffInSeconds($resetAt))
            : 900;
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}

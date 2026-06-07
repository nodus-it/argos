<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\PhaseStatus;
use App\Jobs\DeployDemoJob;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Services\Anthropic\CredentialStore;
use App\Support\ConceptMarkdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PhaseRunner
{
    public const CACHE_KEY_USAGE_LIMIT = 'usage_limit';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly WorkerVolumeReader $volumeReader,
    ) {}

    public function getPhaseLogPath(string $taskName, string $phase): string
    {
        $configDir = config('argos.config_dir');

        return "{$configDir}/tasks/{$taskName}/{$phase}.bg.log";
    }

    /**
     * Read the host-side .bg.log of the just-finished phase run (orchestration +
     * agent stream, in order). Local file read — no Docker round-trip.
     */
    private function readPhaseBgLog(string $taskName, string $phase): ?string
    {
        $path = $this->getPhaseLogPath($taskName, $phase);
        if (! is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false || $content === '' ? null : $content;
    }

    /**
     * Before a concept phase: write task.concept_notes to concept.notes.md in the volume
     * so the worker can read it. Returns the notes value that was written (for post-phase storage).
     */
    public function writeNotesToVolume(Task $task): ?string
    {
        $notes = $task->concept_notes;
        if ($notes === null || $notes === '') {
            return null;
        }

        $process = $this->newProcess([
            'docker', 'run', '--rm', '-i',
            '-v', $task->volumeName().':/workspace',
            'alpine',
            'sh', '-c',
            // chown last: this alpine helper runs as root and is often the FIRST
            // mount of a fresh volume — without handing /workspace back to the
            // worker's uid (1000), the agent container (USER agent) can't mkdir
            // inside it and the phase dies with "Permission denied".
            'mkdir -p /workspace/.agent && cat > /workspace/.agent/concept.notes.md && chown -R 1000:1000 /workspace',
        ]);
        $process->setInput($notes);
        $process->setTimeout(10);
        $process->run();

        return $notes;
    }

    /**
     * Before an implement phase: write task.implement_notes to implement.notes.md in the volume.
     * Returns the notes value that was written (for post-phase storage).
     */
    public function writeImplementNotesToVolume(Task $task): ?string
    {
        $notes = $task->implement_notes;
        if ($notes === null || $notes === '') {
            return null;
        }

        $process = $this->newProcess([
            'docker', 'run', '--rm', '-i',
            '-v', $task->volumeName().':/workspace',
            'alpine',
            'sh', '-c',
            // chown last — see writeNotesToVolume().
            'mkdir -p /workspace/.agent && cat > /workspace/.agent/implement.notes.md && chown -R 1000:1000 /workspace',
        ]);
        $process->setInput($notes);
        $process->setTimeout(10);
        $process->run();

        return $notes;
    }

    /**
     * After a phase completes: read generated content from the volume and store in the DB.
     * Docker is called here (background job), never on page load.
     */
    public function postPhaseSync(Task $task, PhaseRun $phaseRun, string $phase, ?string $notesBeforeRun): void
    {
        if ($phase === 'concept') {
            $conceptMd = $this->volumeReader->readFile($task->volumeName(), '/workspace/.agent/concept.md');
            // Defensive: the agent occasionally wraps its whole reply in a
            // ```markdown … ``` fence despite the system prompt forbidding it.
            // Strip a single outer wrapper so the UI renders the markdown
            // body instead of a giant code block.
            if ($conceptMd !== null) {
                $conceptMd = ConceptMarkdown::stripOuterCodeFence($conceptMd);
            }
            $stateJson = $this->volumeReader->readFile($task->volumeName(), '/workspace/.agent/state.json');

            $phaseRunUpdate = [
                'concept_md' => $conceptMd,
                'concept_notes' => $notesBeforeRun,
            ];

            // When concept fails before Claude runs (e.g. git clone), capture
            // logs/clone.err so the user sees the real reason in the UI.
            // If we have no clone.err but the agent CLI did run and crashed
            // (e.g. 401 from Anthropic), fall back to the captured stderr —
            // the auth error went there, not into the stream-json.
            if ($phaseRun->status !== PhaseStatus::Completed && $conceptMd === null) {
                $cloneErr = $this->volumeReader->readFile($task->volumeName(), '/workspace/.agent/logs/clone.err');
                if ($cloneErr !== null) {
                    $phaseRunUpdate['error_log'] = $cloneErr;
                } else {
                    $stderrPath = "/workspace/.agent/logs/concept.{$phaseRun->iteration}.stderr.log";
                    $stderrLog = $this->volumeReader->readFile($task->volumeName(), $stderrPath);
                    if ($stderrLog !== null && trim($stderrLog) !== '') {
                        $phaseRunUpdate['error_log'] = $stderrLog;
                    }
                }
            }

            // Mirror the implement-phase logic: persist stream_log + stop_reason,
            // and promote a max-turns hit from Failed to Paused. The worker now
            // emits exit 8 (EXIT_MAX_TURNS) directly, which lands as Paused via
            // exitCodeToStatus — but older worker images may still emit exit 1
            // alongside a clean result event, so the stream_log fallback stays.
            $streamLogPath = "/workspace/.agent/logs/concept.{$phaseRun->iteration}.stream.log";
            $streamLog = $this->volumeReader->readFile($task->volumeName(), $streamLogPath);

            // Persist the full .bg.log (orchestration + agent) for the log view,
            // falling back to the agent-only stream from the volume. Stop-reason
            // detection still uses the agent stream (single main session).
            $displayLog = $this->readPhaseBgLog($task->name, 'concept') ?? $streamLog;
            if ($displayLog !== null) {
                $phaseRunUpdate['stream_log'] = $displayLog;
            }

            if ($streamLog !== null) {
                $stopReason = $this->extractStopReasonFromStreamLog($streamLog);
                if ($stopReason !== null) {
                    $phaseRunUpdate['stop_reason'] = $stopReason;

                    if ($stopReason === 'error_max_turns' && $phaseRun->status === PhaseStatus::Failed) {
                        $phaseRunUpdate['status'] = PhaseStatus::Paused;
                    }
                }
            }

            $phaseRun->update($phaseRunUpdate);

            $taskUpdate = ['concept_notes' => null];
            if ($conceptMd !== null) {
                $taskUpdate['concept_md'] = $conceptMd;
            }
            if ($stateJson !== null) {
                $state = json_decode($stateJson, true);
                $featureBranch = $state['repo']['feature_branch'] ?? null;
                if ($featureBranch !== null && $featureBranch !== $task->feature_branch) {
                    $taskUpdate['feature_branch'] = $featureBranch;
                }
            }
            $task->update($taskUpdate);
        }

        if (in_array($phase, ['implement', 'push'], true)) {
            $streamLogPath = "/workspace/.agent/logs/{$phase}.{$phaseRun->iteration}.stream.log";
            $streamLog = $this->volumeReader->readFile($task->volumeName(), $streamLogPath);

            // Persist the full .bg.log for the log view; fall back to the
            // agent-only volume stream when the host log is unavailable.
            $displayLog = $this->readPhaseBgLog($task->name, $phase) ?? $streamLog;
            if ($displayLog !== null) {
                $phaseRun->update(['stream_log' => $displayLog]);
            }

            if ($streamLog !== null) {
                if ($phase === 'implement') {
                    $stopReason = $this->extractStopReasonFromStreamLog($streamLog);
                    if ($stopReason !== null) {
                        $phaseRun->update(['stop_reason' => $stopReason]);

                        // Promote a max-turns hit from "failed" to "paused" so
                        // the UI shows it as resumable, not as an error.
                        if ($stopReason === 'error_max_turns' && $phaseRun->status === PhaseStatus::Failed) {
                            $phaseRun->update(['status' => PhaseStatus::Paused]);
                            $task->update(['current_status' => PhaseStatus::Paused]);
                        }
                    }
                }
            }

            // On failure, surface the CLI's stderr (where auth errors land)
            // in error_log so the UI can show a precise reason instead of
            // just "exit 1". Skip when error_log was already populated.
            // fresh() may be null if the row was removed concurrently (e.g. a
            // job re-attempt cleaning up after a kill) — guard against it, which
            // previously crashed with "read property error_log on null".
            $freshRun = $phaseRun->fresh();
            if ($freshRun !== null
                && $freshRun->status !== PhaseStatus::Completed
                && $freshRun->error_log === null) {
                $stderrPath = "/workspace/.agent/logs/{$phase}.{$phaseRun->iteration}.stderr.log";
                $stderrLog = $this->volumeReader->readFile($task->volumeName(), $stderrPath);
                if ($stderrLog !== null && trim($stderrLog) !== '') {
                    $phaseRun->update(['error_log' => $stderrLog]);
                }
            }
        }

        if (in_array($phase, ['implement', 'respond'], true)) {
            $gateLogs = $this->volumeReader->readQualityGateLogs(
                $task->volumeName(),
                $phaseRun->iteration
            );
            if ($gateLogs !== null && $gateLogs !== []) {
                $phaseRun->update(['quality_gate_logs' => $gateLogs]);
            }
        }

        if ($phase === 'implement') {
            $nontechnical = $this->volumeReader->readFile(
                $task->volumeName(),
                '/workspace/.agent/implement.summary.nontechnical.md'
            );
            $technical = $this->volumeReader->readFile(
                $task->volumeName(),
                '/workspace/.agent/implement.summary.technical.md'
            );

            // Fallback: wenn Claude keine Summary-Dateien geschrieben hat,
            // extrahiere den result-Text aus dem stream_log.
            if ($nontechnical === null && $phaseRun->stream_log !== null) {
                $nontechnical = $this->extractResultTextFromStreamLog($phaseRun->stream_log);
            }

            $phaseRun->update([
                'implement_summary_nontechnical' => $nontechnical,
                'implement_summary_technical' => $technical,
                'implement_notes' => $notesBeforeRun,
            ]);

            $taskUpdate = ['implement_notes' => null];
            if ($nontechnical !== null) {
                $taskUpdate['implement_summary_nontechnical'] = $nontechnical;
            }
            if ($technical !== null) {
                $taskUpdate['implement_summary_technical'] = $technical;
            }
            $task->update($taskUpdate);
        }

        if ($phase === 'push') {
            $resultJson = $phaseRun->result_json;
            $taskUpdate = [];
            if (isset($resultJson['branch']) && $resultJson['branch'] !== $task->feature_branch) {
                $taskUpdate['feature_branch'] = $resultJson['branch'];
            }
            if (isset($resultJson['pr_url']) && $resultJson['pr_url'] !== '' && $resultJson['pr_url'] !== $task->pr_url) {
                $taskUpdate['pr_url'] = $resultJson['pr_url'];
            }
            if ($taskUpdate !== []) {
                $task->update($taskUpdate);
            }
        }
    }

    private function extractResultTextFromStreamLog(string $streamLog): ?string
    {
        foreach (array_reverse(explode("\n", $streamLog)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (is_array($event) && ($event['type'] ?? '') === 'result') {
                $text = trim($event['result'] ?? '');

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }

    public function writeFeedbackToVolume(Task $task, string $feedback): void
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace',
            '-e', 'FEEDBACK',
            'alpine',
            'sh', '-c',
            // chown last — see writeNotesToVolume().
            'mkdir -p /workspace/.agent && printf "%s" "$FEEDBACK" > /workspace/.agent/respond.feedback.md && chown -R 1000:1000 /workspace',
        ]);

        $process->setEnv(['FEEDBACK' => $feedback]);
        $process->setTimeout(10);
        $process->mustRun();
    }

    /**
     * Run a phase synchronously, streaming output to a log file AND a callback.
     * Intended for Artisan commands where live terminal output is needed.
     * Returns the process exit code.
     */
    public function runLive(Task $task, string $phase, callable $output, array $flags = []): int
    {
        $notesBeforeRun = match ($phase) {
            'concept' => $this->writeNotesToVolume($task),
            'implement' => $this->writeImplementNotesToVolume($task),
            default => null,
        };

        $builder = app(PhaseCommandBuilder::class);
        $cmd = $builder->build($task, $phase, $flags);
        $logPath = $this->getPhaseLogPath($task->name, $phase);

        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logPath, '');

        $resolvedModel = $builder->resolveModel($task, $builder->resolveAgentName($task), $phase);
        $phaseRun = app(WorkflowService::class)->startPhase($task, $phase, $resolvedModel);

        Log::channel('argos')->info('Phase started', $this->safeContext($task, $phase, ['iteration' => $phaseRun->iteration]));

        $startedAt = microtime(true);
        $logHandle = fopen($logPath, 'a');
        $stdout = '';

        $process = $this->newProcess($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->run(function (string $type, string $chunk) use ($logHandle, $output, &$stdout): void {
            fwrite($logHandle, $chunk);
            $output($chunk);
            if ($type === Process::OUT) {
                $stdout .= $chunk;
            }
        });

        fclose($logHandle);

        $exitCode = $process->getExitCode() ?? 1;
        $status = $this->exitCodeToStatus($exitCode);
        $duration = round(microtime(true) - $startedAt, 2);

        if ($exitCode !== 0) {
            Log::channel('argos')->warning('Phase exited non-zero', $this->safeContext($task, $phase, [
                'exit_code' => $exitCode,
                'status' => $status,
                'duration_s' => $duration,
            ]));
        } else {
            Log::channel('argos')->info('Phase completed', $this->safeContext($task, $phase, [
                'status' => $status,
                'duration_s' => $duration,
            ]));
        }

        $phaseRun->update($this->phaseRunUpdate($status, $exitCode, $stdout));

        // postPhaseSync writes content (concept_md, implement_summary, …) to the DB.
        // It must run BEFORE the status update so the UI poll cannot stop on 'completed'
        // before the content is available.
        $task->refresh();
        $this->postPhaseSync($task, $phaseRun, $phase, $notesBeforeRun);

        // postPhaseSync may have promoted the phase run (e.g. Failed → Paused for max-turns);
        // use the current phaseRun status as the authoritative final value.
        $task->update([
            'current_phase' => $phase,
            'current_status' => $phaseRun->status,
        ]);

        return $exitCode;
    }

    /**
     * Run a phase synchronously, streaming output to a log file.
     * Intended to be called from a queue job.
     */
    public function runBlocking(Task $task, string $phase, array $flags = []): void
    {
        $notesBeforeRun = match ($phase) {
            'concept' => $this->writeNotesToVolume($task),
            'implement' => $this->writeImplementNotesToVolume($task),
            default => null,
        };

        $builder = app(PhaseCommandBuilder::class);
        $cmd = $builder->build($task, $phase, $flags);
        $logPath = $this->getPhaseLogPath($task->name, $phase);

        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logPath, '');

        $resolvedModel = $builder->resolveModel($task, $builder->resolveAgentName($task), $phase);
        $phaseRun = app(WorkflowService::class)->startPhase($task, $phase, $resolvedModel);

        Log::channel('argos')->info('Phase started', $this->safeContext($task, $phase, ['iteration' => $phaseRun->iteration]));

        $startedAt = microtime(true);
        $logHandle = fopen($logPath, 'a');
        $stdout = '';

        $process = $this->newProcess($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->run(function (string $type, string $chunk) use ($logHandle, &$stdout): void {
            fwrite($logHandle, $chunk);
            if ($type === Process::OUT) {
                $stdout .= $chunk;
            }
        });

        fclose($logHandle);

        $exitCode = $process->getExitCode() ?? 1;
        $status = $this->exitCodeToStatus($exitCode);
        $duration = round(microtime(true) - $startedAt, 2);

        if ($exitCode !== 0) {
            Log::channel('argos')->warning('Phase exited non-zero', $this->safeContext($task, $phase, [
                'exit_code' => $exitCode,
                'status' => $status,
                'duration_s' => $duration,
            ]));
        } else {
            Log::channel('argos')->info('Phase completed', $this->safeContext($task, $phase, [
                'status' => $status,
                'duration_s' => $duration,
            ]));
        }

        $phaseRun->update($this->phaseRunUpdate($status, $exitCode, $stdout));

        // Defensive cost recovery: if the worker crashed before emitting its
        // result line (e.g. early `return 3` from a phase script), the stdout
        // result_json is missing — but the per-iteration Claude `*.result.json`
        // files were already written to the volume by the streaming pipeline,
        // so we can still salvage cost/token counters from there.
        if ($phaseRun->fresh()->cost_usd === null && $exitCode !== 0) {
            $this->recoverUsageFromVolume($task, $phaseRun, $phase);
        }

        if ($exitCode === 7) {
            $resetAt = $this->readUsageLimitResetAt($task);
            $this->storeUsageLimit($resetAt);
        }

        // postPhaseSync writes content (concept_md, implement_summary, …) to the DB.
        // It must run BEFORE the status update so the UI poll cannot stop on 'completed'
        // before the content is available.
        $task->refresh();
        $this->postPhaseSync($task, $phaseRun, $phase, $notesBeforeRun);

        // postPhaseSync may have promoted the phase run (e.g. Failed → Paused for max-turns);
        // use the current phaseRun status as the authoritative final value.
        $task->update([
            'current_phase' => $phase,
            'current_status' => $phaseRun->status,
        ]);

        // After a successful implement run with existing PR: auto-trigger push
        if ($phase === 'implement' && $phaseRun->status === PhaseStatus::Completed && $task->fresh()->pr_url !== null) {
            Log::channel('argos')->info('Auto-triggering push after implement', $this->safeContext($task, 'push'));
            RunPhaseJob::dispatch($task->id, 'push');
        }

        // After a successful implement run: (re)deploy the live demo when the
        // project enabled it and previews are switched on. The implemented code
        // is already in the task volume, which the deployer mounts into the demo.
        if ($phase === 'implement'
            && $phaseRun->status === PhaseStatus::Completed
            && config('argos.preview.enabled')
            && $task->repoProfile?->live_demo_enabled) {
            Log::channel('argos')->info('Dispatching live-demo deploy after implement', $this->safeContext($task, 'implement'));
            DeployDemoJob::dispatch($task->id);
        }
    }

    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }

    private function exitCodeToStatus(int $exitCode): PhaseStatus
    {
        return match ($exitCode) {
            0 => PhaseStatus::Completed,
            4 => PhaseStatus::QualityGateFailed,
            5 => PhaseStatus::NoChanges,
            6 => PhaseStatus::LockBlocked,
            7 => PhaseStatus::RateLimited,
            8 => PhaseStatus::Paused,
            default => PhaseStatus::Failed,
        };
    }

    /**
     * Build the PhaseRun update array, extracting cost/token data from the
     * result JSON the worker emits as the last stdout line.
     *
     * @return array<string, mixed>
     */
    private function phaseRunUpdate(PhaseStatus $status, int $exitCode, string $stdout): array
    {
        $update = [
            'status' => $status,
            'finished_at' => now(),
            'exit_code' => $exitCode,
        ];

        $resultJson = $this->parseResultJson($stdout);
        if ($resultJson !== null) {
            $update['result_json'] = $resultJson;
            $update['cost_usd'] = isset($resultJson['claude_total_cost_usd'])
                ? (float) $resultJson['claude_total_cost_usd']
                : null;
            $update['input_tokens'] = isset($resultJson['input_tokens'])
                ? (int) $resultJson['input_tokens']
                : null;
            $update['output_tokens'] = isset($resultJson['output_tokens'])
                ? (int) $resultJson['output_tokens']
                : null;
        }

        return $update;
    }

    /**
     * Find the last `result` event in a Claude stream log and return its
     * subtype (e.g. "success", "error_max_turns", "error").
     */
    private function extractStopReasonFromStreamLog(string $streamLog): ?string
    {
        foreach (array_reverse(explode("\n", rtrim($streamLog))) as $line) {
            if ($line === '' || ! str_contains($line, '"type":"result"')) {
                continue;
            }
            $event = json_decode($line, true);
            if (is_array($event) && ($event['type'] ?? '') === 'result') {
                $subtype = $event['subtype'] ?? null;

                return is_string($subtype) && $subtype !== '' ? $subtype : null;
            }
        }

        return null;
    }

    /**
     * Find the last line of stdout that is valid JSON with the worker result fields.
     *
     * @return array<string, mixed>|null
     */
    private function parseResultJson(string $stdout): ?array
    {
        foreach (array_reverse(explode("\n", $stdout)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['phase'], $decoded['status'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build a log context array that is safe to persist — never includes tokens or credentials.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function safeContext(Task $task, string $phase, array $extra = []): array
    {
        return array_merge([
            'task' => $task->name,
            'task_id' => $task->id,
            'phase' => $phase,
        ], $extra);
    }

    /**
     * Read every Claude `*.result.json` for this iteration (initial + fixN
     * retries) from the worker volume, sum their cost/token totals, and
     * persist them on the phase run. The shell script returns one JSON line
     * per matching file; we sum total_cost_usd + usage.{input,output}_tokens.
     *
     * This is a recovery path for phase scripts that died before
     * `result_emit` (e.g. implement `return 3` on is_error=true). The
     * happy-path phaseRunUpdate() handles the normal case.
     */
    private function recoverUsageFromVolume(Task $task, PhaseRun $phaseRun, string $phase): void
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
     * Read the usage_limit.env file the worker writes when it detects a rate limit.
     * Returns the reset timestamp if the file contained one, otherwise null.
     */
    private function readUsageLimitResetAt(Task $task): ?Carbon
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
     * The banner component and RunPhaseJob both read this key.
     */
    public function storeUsageLimit(?Carbon $resetAt): void
    {
        $data = [
            'active' => true,
            'reset_at' => $resetAt?->toIso8601String(),
            'detected_at' => now()->toIso8601String(),
        ];

        $ttl = ($resetAt !== null && $resetAt->isFuture())
            ? $resetAt->clone()->addMinutes(5)
            : now()->addHours(2);

        Cache::put(self::CACHE_KEY_USAGE_LIMIT, $data, $ttl);

        Log::channel('argos')->warning('Usage limit detected and stored', [
            'reset_at' => $data['reset_at'],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\AgentCredential;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Anthropic\CredentialStore;
use App\Workers\Agents\MaterializedAgentCredential;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PhaseRunner
{
    public const CACHE_KEY_USAGE_LIMIT = 'usage_limit';

    public function __construct(
        private readonly CredentialStore $credentials,
    ) {}

    public function getPhaseLogPath(string $taskName, string $phase): string
    {
        $configDir = config('argos.config_dir');

        return "{$configDir}/tasks/{$taskName}/{$phase}.bg.log";
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
            'mkdir -p /workspace/.agent && cat > /workspace/.agent/concept.notes.md',
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
            'mkdir -p /workspace/.agent && cat > /workspace/.agent/implement.notes.md',
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
            $conceptMd = $this->readFileFromVolume($task->volumeName(), '/workspace/.agent/concept.md');
            $stateJson = $this->readFileFromVolume($task->volumeName(), '/workspace/.agent/state.json');

            $phaseRunUpdate = [
                'concept_md' => $conceptMd,
                'concept_notes' => $notesBeforeRun,
            ];

            // When concept fails before Claude runs (e.g. git clone), capture
            // logs/clone.err so the user sees the real reason in the UI.
            if ($phaseRun->status !== PhaseStatus::Completed && $conceptMd === null) {
                $cloneErr = $this->readFileFromVolume($task->volumeName(), '/workspace/.agent/logs/clone.err');
                if ($cloneErr !== null) {
                    $phaseRunUpdate['error_log'] = $cloneErr;
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
            $streamLog = $this->readFileFromVolume($task->volumeName(), $streamLogPath);
            if ($streamLog !== null) {
                $phaseRun->update(['stream_log' => $streamLog]);

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
        }

        if ($phase === 'implement') {
            $nontechnical = $this->readFileFromVolume(
                $task->volumeName(),
                '/workspace/.agent/implement.summary.nontechnical.md'
            );
            $technical = $this->readFileFromVolume(
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

    protected function readFileFromVolume(string $volumeName, string $filePath): ?string
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', "{$volumeName}:/workspace:ro",
            'alpine',
            'cat', $filePath,
        ]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        return $output !== '' ? $output : null;
    }

    public function writeFeedbackToVolume(Task $task, string $feedback): void
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace',
            '-e', 'FEEDBACK',
            'alpine',
            'sh', '-c',
            'mkdir -p /workspace/.agent && printf "%s" "$FEEDBACK" > /workspace/.agent/respond.feedback.md',
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

        $cmd = $this->buildCommand($task, $phase, $flags);
        $logPath = $this->getPhaseLogPath($task->name, $phase);

        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logPath, '');

        $phaseRun = app(WorkflowService::class)->startPhase($task, $phase);

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

        $cmd = $this->buildCommand($task, $phase, $flags);
        $logPath = $this->getPhaseLogPath($task->name, $phase);

        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logPath, '');

        $phaseRun = app(WorkflowService::class)->startPhase($task, $phase);

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

    private function buildCommand(Task $task, string $phase, array $flags = []): array
    {
        $profile = $task->repoProfile;

        if ($profile === null) {
            Log::channel('argos')->error('Phase cannot start: task has no repo profile', ['task' => $task->name, 'phase' => $phase]);
            throw new \RuntimeException(
                "Task '{$task->name}' hat kein Repo-Profil — Phase kann nicht gestartet werden."
            );
        }

        $agentName = $this->resolveAgentName($task);
        $materializedCredential = $this->materializeCredential($task, $agentName);

        $workerImage = $this->resolveWorkerImage($task);
        $phaseFlags = $flags !== [] ? json_encode($flags) : '{}';

        $maxTurns = $this->resolveMaxTurns($task, $phase, $flags);
        $resumeSessionId = $this->resolveResumeSessionId($task, $phase, $flags);
        $modelId = $this->resolveModel($task, $agentName, $phase);

        $cmd = [
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace',
            '-v', 'composer_cache:/home/agent/.composer/cache',
            '-v', 'npm_cache:/home/agent/.npm',
            '--memory', (string) config('argos.docker.memory_limit'),
            '--cpus',   (string) config('argos.docker.cpu_limit'),
            '-e', "PHASE={$phase}",
            '-e', "TASK_ID={$task->name}",
            '-e', "REPO_URL={$profile->url}",
            '-e', "REPO_TOKEN={$this->resolveRepoToken($profile)}",
            '-e', "REPO_PLATFORM={$profile->platform->value}",
            '-e', 'BASE_BRANCH='.($task->base_branch ?: $profile->default_branch),
            '-e', "AGENT_NAME={$agentName->value}",
            '-e', "TASK_DESCRIPTION={$task->description}",
            '-e', "PHASE_FLAGS={$phaseFlags}",
            '-e', "MAX_TURNS={$maxTurns}",
            '-e', 'CLAUDE_CONFIG_DIR=/workspace/.agent/claude-state',
            '-e', 'LOG_LEVEL=info',
            '-e', "CLAUDE_MODEL={$modelId}",
        ];

        foreach ($materializedCredential->envVars as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = "{$key}={$value}";
        }

        if ($resumeSessionId !== null) {
            $cmd[] = '-e';
            $cmd[] = "RESUME_SESSION_ID={$resumeSessionId}";
        }

        $commitUser = $task->user;
        if ($commitUser !== null) {
            $cmd[] = '-e';
            $cmd[] = "COMMIT_USER_NAME={$commitUser->name}";
            $cmd[] = '-e';
            $cmd[] = "COMMIT_USER_EMAIL={$commitUser->email}";
        }

        if (! empty($flags['force_unlock'])) {
            $cmd[] = '-e';
            $cmd[] = 'FORCE_UNLOCK=1';
        }

        $cmd[] = $workerImage;
        $cmd[] = $phase;
        $cmd[] = $task->name;

        return $cmd;
    }

    private function resolveRepoToken(RepoProfile $profile): string
    {
        return $profile->resolveToken();
    }

    /**
     * Resolution order: task override → repo_profile setting → default
     * (claude-code). Mirrors what WorkerImageResolver does for the
     * stack/agent pair so AGENT_NAME stays consistent with the chosen
     * worker image.
     */
    private function resolveAgentName(Task $task): AgentName
    {
        return $task->worker_agent_name_override
            ?? $task->repoProfile?->worker_agent_name
            ?? AgentName::ClaudeCode;
    }

    /**
     * Materialise the agent's credential into env-vars for `docker run`.
     *
     * Resolution order:
     *   1. Explicit `task.agent_credential_id` if set
     *   2. First active AgentCredential for the resolved agent (matches
     *      what TaskResource's helper text promises)
     *   3. Whatever the runner falls back to when handed null — claude
     *      reads the legacy env/file token, codex throws because it has
     *      no shared-secret fallback
     */
    private function materializeCredential(Task $task, AgentName $agentName): MaterializedAgentCredential
    {
        $runner = $agentName->runner();

        $credential = $task->agentCredential
            ?? $this->resolveDefaultCredential($agentName);

        return $runner->materializeCredential($credential);
    }

    /**
     * First active credential for the agent, ordered by created_at so the
     * "first one I made" intuition matches what the form's "Leer = erste
     * aktive Credential" helper text claims.
     */
    private function resolveDefaultCredential(AgentName $agentName): ?AgentCredential
    {
        return AgentCredential::query()
            ->where('agent_name', $agentName->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Resolves the worker image tag via the compose pipeline, building
     * the image on demand if the (stack × agent × version) tuple does
     * not yet exist locally. The resolver is fetched lazily via the
     * container because phpunit's partialMock skips the constructor and
     * any readonly resolver property would land in an
     * "accessed before initialization" error.
     */
    private function resolveWorkerImage(Task $task): string
    {
        return app(WorkerImageResolver::class)->resolveOrBuild($task);
    }

    /**
     * Priority: explicit flags['max_turns'] > phase-specific task setting >
     * phase-aware config default. Concept and implement have separate task
     * overrides (`task.max_turns_concept`, `task.max_turns_implement`) so the
     * UI can tune each independently — concept usually needs ~30, implement
     * ~200.
     *
     * @param  array<string, mixed>  $flags
     */
    private function resolveMaxTurns(Task $task, string $phase, array $flags): int
    {
        if (isset($flags['max_turns']) && (int) $flags['max_turns'] > 0) {
            return (int) $flags['max_turns'];
        }

        $taskOverride = $phase === 'concept'
            ? $task->max_turns_concept
            : $task->max_turns_implement;

        if ($taskOverride !== null && $taskOverride > 0) {
            return $taskOverride;
        }

        $configKey = $phase === 'concept'
            ? 'argos.concept.max_turns_default'
            : 'argos.implement.max_turns_default';

        return (int) config($configKey, $phase === 'concept' ? 30 : 200);
    }

    /**
     * For continue-mode implement runs: find the session_id of the last
     * implement run so the worker can call `claude --resume <id>`.
     *
     * @param  array<string, mixed>  $flags
     */
    private function resolveResumeSessionId(Task $task, string $phase, array $flags): ?string
    {
        if ($phase !== 'implement') {
            return null;
        }
        if (empty($flags['continue'])) {
            return null;
        }

        $lastRun = $task->phaseRuns()
            ->where('phase', 'implement')
            ->orderByDesc('iteration')
            ->first();

        $sessionId = $lastRun?->result_json['claude_session_id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            // Fallback: parse from stream_log (older runs may not have result_json populated yet)
            $sessionId = $this->extractSessionIdFromStreamLog($lastRun?->stream_log);
        }

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    /**
     * Resolve the model id to send to the worker for this phase, agent-aware.
     *
     * Phase mapping:
     *  - respond: concept-review uses the concept model, code-review the implement model
     *  - commit-message: a dedicated cheap-model slot
     *  - everything else: takes its own slot (concept/implement)
     *
     * Resolution order per slot: task override → repo profile default →
     * agent-spec default for the phase. The env var stays named
     * CLAUDE_MODEL for now because the worker scripts and Claude runner
     * read it as such; the Codex runner ignores it (Codex picks via its
     * own --model arg if needed).
     */
    private function resolveModel(Task $task, AgentName $agentName, string $phase): string
    {
        $effectivePhase = match ($phase) {
            'respond' => $task->workflow_status === WorkflowStatus::ConceptReview ? 'concept' : 'implement',
            default => $phase,
        };

        $taskModel = match ($effectivePhase) {
            'concept' => $task->model_concept,
            'implement' => $task->model_implement,
            default => null,
        };
        if ($taskModel !== null && $taskModel !== '') {
            return $taskModel;
        }

        $profile = $task->repoProfile;
        $profileModel = match ($effectivePhase) {
            'concept' => $profile?->model_concept,
            'implement' => $profile?->model_implement,
            default => null,
        };
        if ($profileModel !== null && $profileModel !== '') {
            return $profileModel;
        }

        $spec = $agentName->spec();
        $default = $spec->defaultModel($effectivePhase);

        return $default ?? '';
    }

    private function extractSessionIdFromStreamLog(?string $streamLog): ?string
    {
        if ($streamLog === null || $streamLog === '') {
            return null;
        }
        // The first line is the system/init event with the session_id field.
        $firstLine = strtok($streamLog, "\n");
        if ($firstLine === false) {
            return null;
        }
        $event = json_decode($firstLine, true);

        return is_array($event) && isset($event['session_id']) && is_string($event['session_id'])
            ? $event['session_id']
            : null;
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
     * Read the usage_limit.env file the worker writes when it detects a rate limit.
     * Returns the reset timestamp if the file contained one, otherwise null.
     */
    private function readUsageLimitResetAt(Task $task): ?Carbon
    {
        $content = $this->readFileFromVolume(
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

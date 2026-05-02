<?php

declare(strict_types=1);

namespace App\Domain\Phase;

use App\Domain\Credentials\CredentialStore;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\Task;
use Symfony\Component\Process\Process;

class PhaseRunner
{
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

    private function readFileFromVolume(string $volumeName, string $filePath): ?string
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

    public function writeFeedbackToVolume(string $taskName, string $feedback): void
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace',
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

        $phaseRun = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => $phase,
            'iteration' => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $task->update([
            'current_phase' => $phase,
            'current_status' => 'running',
        ]);

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

        $phaseRun->update($this->phaseRunUpdate($status, $exitCode, $stdout));

        $task->update([
            'current_phase' => $phase,
            'current_status' => $status,
        ]);

        $task->refresh();
        $this->postPhaseSync($task, $phaseRun, $phase, $notesBeforeRun);

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

        $phaseRun = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => $phase,
            'iteration' => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $task->update([
            'current_phase' => $phase,
            'current_status' => 'running',
        ]);

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

        $phaseRun->update($this->phaseRunUpdate($status, $exitCode, $stdout));

        $task->update([
            'current_phase' => $phase,
            'current_status' => $status,
        ]);

        $task->refresh();
        $this->postPhaseSync($task, $phaseRun, $phase, $notesBeforeRun);

        // After a successful implement run with existing PR: auto-trigger push
        if ($phase === 'implement' && $status === 'completed' && $task->fresh()->pr_url !== null) {
            RunPhaseJob::dispatch($task->id, 'push');
        }
    }

    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }

    private function exitCodeToStatus(int $exitCode): string
    {
        return match ($exitCode) {
            0 => 'completed',
            4 => 'quality_gate_failed',
            5 => 'no_changes',
            default => 'failed',
        };
    }

    /**
     * Build the PhaseRun update array, extracting cost/token data from the
     * result JSON the worker emits as the last stdout line.
     *
     * @return array<string, mixed>
     */
    private function phaseRunUpdate(string $status, int $exitCode, string $stdout): array
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
            throw new \RuntimeException(
                "Task '{$task->name}' hat kein Repo-Profil — Phase kann nicht gestartet werden."
            );
        }

        // Token: env var takes precedence (containerised manager), file-based fallback for local dev
        $claudeToken = config('argos.claude_token') ?? $this->credentials->getClaudeToken();

        if ($claudeToken === null) {
            throw new \RuntimeException(
                'Kein Claude OAuth Token konfiguriert. Bitte CLAUDE_CODE_OAUTH_TOKEN setzen.'
            );
        }

        $workerImage = $profile->worker_image
            ?: config('argos.worker_image', 'ghcr.io/nodus-it/argos-worker:php8.4');
        $phaseFlags = $flags !== [] ? json_encode($flags) : '{}';

        return [
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace',
            '-v', 'composer_cache:/home/agent/.composer/cache',
            '-v', 'npm_cache:/home/agent/.npm',
            '--memory', env('ARGOS_MEM_LIMIT', '4g'),
            '--cpus',   env('ARGOS_CPU_LIMIT', '2'),
            '-e', "PHASE={$phase}",
            '-e', "TASK_ID={$task->name}",
            '-e', "REPO_URL={$profile->url}",
            '-e', "REPO_TOKEN={$profile->token}",
            '-e', "BASE_BRANCH={$profile->default_branch}",
            '-e', "CLAUDE_CODE_OAUTH_TOKEN={$claudeToken}",
            '-e', "TASK_DESCRIPTION={$task->description}",
            '-e', "PHASE_FLAGS={$phaseFlags}",
            '-e', 'LOG_LEVEL=info',
            $workerImage,
            $phase,
            $task->name,
        ];
    }
}

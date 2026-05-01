<?php

declare(strict_types=1);

namespace App\Domain\Phase;

use App\Domain\Credentials\CredentialStore;
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

    public function writeFeedbackToVolume(string $taskName, string $feedback): void
    {
        $process = new Process([
            'docker', 'run', '--rm',
            '-v', "task_ws_{$taskName}:/workspace",
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

        $process = new Process($cmd);
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

        return $exitCode;
    }

    /**
     * Run a phase synchronously, streaming output to a log file.
     * Intended to be called from a queue job.
     */
    public function runBlocking(Task $task, string $phase, array $flags = []): void
    {
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

        $process = new Process($cmd);
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

        $workerImage = config('argos.worker_image', 'ghcr.io/nodus-it/argos-worker:latest');
        $phaseFlags = $flags !== [] ? json_encode($flags) : '{}';

        return [
            'docker', 'run', '--rm',
            '-v', "task_ws_{$task->name}:/workspace",
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

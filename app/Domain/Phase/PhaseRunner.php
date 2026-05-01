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
     * Start a phase in the background. Returns immediately; phase runs detached.
     */
    public function startBackground(Task $task, string $phase, array $flags = []): void
    {
        $cmd = $this->buildCommand($task, $phase, $flags);
        $logPath = $this->getPhaseLogPath($task->name, $phase);

        $logDir = dirname($logPath);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logPath, '');

        $shellParts = array_map('escapeshellarg', $cmd);
        $shellCmd = 'nohup '.implode(' ', $shellParts)
            .' >> '.escapeshellarg($logPath).' 2>&1 &';

        PhaseRun::create([
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

        shell_exec($shellCmd);
    }

    /**
     * Run a phase in the foreground, yielding output chunks as a generator.
     */
    public function run(Task $task, string $phase, array $flags = []): \Generator
    {
        $cmd = $this->buildCommand($task, $phase, $flags);

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $phaseRun = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => $phase,
            'iteration' => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $chunks = [];
        $process->start(function (string $type, string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        while ($process->isRunning()) {
            while ($chunks !== []) {
                yield array_shift($chunks);
            }
            usleep(50_000);
        }

        foreach ($chunks as $chunk) {
            yield $chunk;
        }

        $exitCode = $process->getExitCode() ?? 1;

        $status = match ($exitCode) {
            0 => 'completed',
            4 => 'quality_gate_failed',
            5 => 'no_changes',
            default => 'failed',
        };

        $phaseRun->update([
            'status' => $status,
            'finished_at' => now(),
            'exit_code' => $exitCode,
        ]);

        $task->update([
            'current_phase' => $phase,
            'current_status' => $status,
        ]);
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

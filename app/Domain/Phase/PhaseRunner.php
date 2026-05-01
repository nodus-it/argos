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
        $repoRoot = config('argos.repo_root');
        $cmd = $this->buildCommand($task, $phase, $flags);
        $logPath = $this->getPhaseLogPath($task->name, $phase);

        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Truncate old log so watch always starts from the beginning
        file_put_contents($logPath, '');

        $shellParts = array_map('escapeshellarg', $cmd);
        $shellCmd = 'cd ' . escapeshellarg($repoRoot)
            . ' && nohup ' . implode(' ', $shellParts)
            . ' >> ' . escapeshellarg($logPath) . ' 2>&1 &';

        PhaseRun::create([
            'task_id'   => $task->id,
            'phase'     => $phase,
            'iteration' => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status'    => 'running',
            'started_at' => now(),
        ]);

        $task->update([
            'current_phase'  => $phase,
            'current_status' => 'running',
        ]);

        shell_exec($shellCmd);
    }

    /**
     * Run a phase in the foreground, yielding output chunks as a generator.
     */
    public function run(Task $task, string $phase, array $flags = []): \Generator
    {
        $repoRoot = config('argos.repo_root');
        $cmd = $this->buildCommand($task, $phase, $flags);

        $process = new Process($cmd, $repoRoot);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $phaseRun = PhaseRun::create([
            'task_id'    => $task->id,
            'phase'      => $phase,
            'iteration'  => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status'     => 'running',
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
            'status'      => $status,
            'finished_at' => now(),
            'exit_code'   => $exitCode,
        ]);

        $task->update([
            'current_phase'  => $phase,
            'current_status' => $status,
        ]);
    }

    private function buildCommand(Task $task, string $phase, array $flags = []): array
    {
        $repoRoot  = config('argos.repo_root');
        $configDir = config('argos.config_dir');

        $profile = $task->repoProfile;

        if ($profile === null) {
            throw new \RuntimeException(
                "Task '{$task->name}' hat kein Repo-Profil — Phase kann nicht gestartet werden."
            );
        }

        $claudeToken = $this->credentials->getClaudeToken();

        if ($claudeToken === null) {
            throw new \RuntimeException(
                'Kein Claude OAuth Token konfiguriert. Bitte zuerst `php artisan argos:init` ausführen.'
            );
        }

        $descriptionPath = "{$configDir}/tasks/{$task->name}/description.md";

        $cmd = [
            'docker', 'compose',
            '-f', "{$repoRoot}/docker-compose.yml",
            'run', '--rm',
            '-v', "task_ws_{$task->name}:/workspace",
        ];

        if (is_file($descriptionPath)) {
            $cmd[] = '-v';
            $cmd[] = "{$descriptionPath}:/run/agent/description.md:ro";
        }

        return array_merge($cmd, [
            '-e', "PHASE={$phase}",
            '-e', "TASK_ID={$task->name}",
            '-e', "REPO_URL={$profile->url}",
            '-e', "REPO_TOKEN={$profile->token}",
            '-e', "BASE_BRANCH={$profile->default_branch}",
            '-e', "CLAUDE_CODE_OAUTH_TOKEN={$claudeToken}",
            '-e', 'PHASE_FLAGS=' . (($flags !== []) ? json_encode($flags) : '{}'),
            '-e', 'LOG_LEVEL=info',
            'worker',
            $phase,
            $task->name,
        ]);
    }
}

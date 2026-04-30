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

    public function run(Task $task, string $phase, array $flags = []): \Generator
    {
        $repoRoot = config('argos.repo_root');
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

        $cmd = array_merge($cmd, [
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

        $process = new Process($cmd, $repoRoot);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $phaseRun = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => $phase,
            'iteration' => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status' => 'running',
            'started_at' => now(),
        ]);

        // Collect chunks via iterator so we can yield them
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

        // Drain any remaining chunks after process exits
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
}

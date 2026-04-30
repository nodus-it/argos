<?php

declare(strict_types=1);

namespace App\Domain\Phase;

use App\Models\PhaseRun;
use App\Models\Task;
use Symfony\Component\Process\Process;

class PhaseRunner
{
    public function run(Task $task, string $phase, array $flags = []): \Generator
    {
        $repoRoot = config('argos.repo_root');

        $cmd = ['docker', 'compose', 'run', '--rm', 'worker', $phase, $task->name, ...$flags];

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

        $exitCode = $process->getExitCode();
        $status = $exitCode === 0 ? 'completed' : 'failed';

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

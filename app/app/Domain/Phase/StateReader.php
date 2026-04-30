<?php

declare(strict_types=1);

namespace App\Domain\Phase;

use Symfony\Component\Process\Process;

class StateReader
{
    public function read(string $taskName): ?array
    {
        $repoRoot = config('argos.repo_root');

        // Read state.json from the named Docker volume via a one-shot container
        $process = new Process(
            ['docker', 'run', '--rm',
                '-v', "argos-tasks:/tasks:ro",
                'alpine',
                'cat', "/tasks/{$taskName}/state.json",
            ],
            $repoRoot,
        );

        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $decoded = json_decode($process->getOutput(), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getPhaseStatus(string $taskName, string $phase): ?string
    {
        $state = $this->read($taskName);

        return $state['phases'][$phase]['status'] ?? null;
    }

    public function readNotes(string $taskName): ?string
    {
        $process = new Process(
            ['docker', 'run', '--rm',
                '-v', "task_ws_{$taskName}:/workspace:ro",
                '--entrypoint', 'sh',
                'agent-worker:latest',
                '-c', 'cat /workspace/.agent/concept.notes.md',
            ],
        );

        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        return $output !== '' ? $output : null;
    }
}

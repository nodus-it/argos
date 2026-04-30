<?php

declare(strict_types=1);

namespace App\Domain\Phase;

use App\Models\PhaseRun;
use App\Models\Task;
use Symfony\Component\Process\Process;

class StateReader
{
    /**
     * Read state.json from a task's workspace volume.
     */
    public function read(string $taskName): ?array
    {
        $process = new Process([
            'docker', 'run', '--rm',
            '-v', "task_ws_{$taskName}:/workspace:ro",
            'alpine',
            'cat', '/workspace/.agent/state.json',
        ]);

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

        return $state['phases'][$phase]['current_status'] ?? null;
    }

    public function readNotes(string $taskName): ?string
    {
        $process = new Process([
            'docker', 'run', '--rm',
            '-v', "task_ws_{$taskName}:/workspace:ro",
            'alpine',
            'cat', '/workspace/.agent/concept.notes.md',
        ]);

        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        return $output !== '' ? $output : null;
    }

    /**
     * Read state.json and sync completed phases back into the DB.
     * Call this when entering the task detail view to refresh stale 'running' records.
     */
    public function syncToDb(Task $task): void
    {
        $state = $this->read($task->name);
        if ($state === null) {
            return;
        }

        $phaseOrder = ['concept', 'implement', 'diff', 'push'];
        $lastPhase  = null;
        $lastStatus = null;

        foreach ($phaseOrder as $phase) {
            $phaseState = $state['phases'][$phase] ?? null;
            if ($phaseState === null) {
                continue;
            }

            $stateStatus = $phaseState['current_status'] ?? null;
            if ($stateStatus === null || $stateStatus === 'pending') {
                continue;
            }

            $lastPhase  = $phase;
            $lastStatus = $stateStatus;

            if ($stateStatus === 'running') {
                continue;
            }

            // Update DB records that are still marked 'running' for this phase
            PhaseRun::where('task_id', $task->id)
                ->where('phase', $phase)
                ->where('status', 'running')
                ->update([
                    'status'      => $stateStatus,
                    'finished_at' => now(),
                ]);
        }

        if ($lastPhase !== null && $lastStatus !== null) {
            $task->update([
                'current_phase'  => $lastPhase,
                'current_status' => $lastStatus,
            ]);
        }
    }
}

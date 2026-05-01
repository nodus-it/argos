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
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace:ro',
            'alpine',
            'cat', '/workspace/.agent/state.json',
        ]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
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

    public function readConcept(string $taskName): ?string
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace:ro',
            'alpine',
            'cat', '/workspace/.agent/concept.md',
        ]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        return $output !== '' ? $output : null;
    }

    public function writeNotes(string $taskName, string $content): bool
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace',
            'alpine',
            'sh', '-c',
            'mkdir -p /workspace/.agent && printf "%s" "$NOTE_CONTENT" > /workspace/.agent/concept.notes.md',
        ]);

        $process->setEnv(['NOTE_CONTENT' => $content]);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    public function readNotes(string $taskName): ?string
    {
        $process = $this->newProcess([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace:ro',
            'alpine',
            'cat', '/workspace/.agent/concept.notes.md',
        ]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        return $output !== '' ? $output : null;
    }

    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
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

        $phaseOrder = ['concept', 'implement', 'diff', 'push', 'respond'];
        $lastPhase = null;
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

            $lastPhase = $phase;
            $lastStatus = $stateStatus;

            if ($stateStatus === 'running') {
                continue;
            }

            // Update DB records that are still marked 'running' for this phase
            PhaseRun::where('task_id', $task->id)
                ->where('phase', $phase)
                ->where('status', 'running')
                ->update([
                    'status' => $stateStatus,
                    'finished_at' => now(),
                ]);
        }

        $updates = [];

        if ($lastPhase !== null && $lastStatus !== null) {
            $updates['current_phase'] = $lastPhase;
            $updates['current_status'] = $lastStatus;
        }

        $featureBranch = $state['repo']['feature_branch'] ?? null;
        if ($featureBranch !== null && $featureBranch !== $task->feature_branch) {
            $updates['feature_branch'] = $featureBranch;
        }

        // pr_url kommt aus repo.pr_url (gesetzt von push.sh via state_set_pr_url)
        $prUrl = $state['repo']['pr_url'] ?? null;
        if ($prUrl !== null && $prUrl !== '' && $prUrl !== $task->pr_url) {
            $updates['pr_url'] = $prUrl;
        }

        if ($updates !== []) {
            $task->update($updates);
        }
    }
}

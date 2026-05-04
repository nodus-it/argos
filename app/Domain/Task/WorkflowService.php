<?php

declare(strict_types=1);

namespace App\Domain\Task;

use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\Task;

class WorkflowService
{
    /**
     * Create a PhaseRun and mark the task as running for the given phase.
     */
    public function startPhase(Task $task, string $phase): PhaseRun
    {
        $task->update([
            'current_phase' => $phase,
            'current_status' => 'running',
        ]);

        return PhaseRun::create([
            'task_id' => $task->id,
            'phase' => $phase,
            'iteration' => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Reset workflow_status to the appropriate running state when retrying
     * a phase from a failed or paused workflow status.
     */
    public function retryPhase(Task $task, string $phase): void
    {
        $task->update([
            'workflow_status' => $task->workflow_status->retryingPhase($phase),
            'current_phase' => $phase,
            'current_status' => 'running',
        ]);
    }

    /**
     * Advance workflow_status after a phase completes.
     * Canonical implementation — Task::advanceWorkflow() delegates here.
     */
    public function completePhase(Task $task, string $phase, string $phaseStatus): void
    {
        if ($phase === 'implement' && $phaseStatus === 'completed') {
            if ($task->repoProfile?->auto_pr) {
                RunPhaseJob::dispatch($task->id, 'push');

                // workflow_status stays as-is until push finishes
                return;
            }

            // No auto_pr: ensure the status reflects implement finished, not that it failed.
            if ($task->workflow_status !== WorkflowStatus::ImplementRunning) {
                $task->update(['workflow_status' => WorkflowStatus::ImplementRunning]);
            }

            return;
        }

        $next = WorkflowStatus::afterPhase($phase, $phaseStatus);
        if ($next !== null) {
            $task->update(['workflow_status' => $next]);
        }
    }

    /**
     * Mark phase runs stuck in 'running' for more than 2 hours as failed.
     * Pure DB operation — no Docker calls.
     */
    public function markStaleRunsAsFailed(Task $task): void
    {
        PhaseRun::where('task_id', $task->id)
            ->where('status', 'running')
            ->where('started_at', '<', now()->subHours(2))
            ->update(['status' => 'failed', 'finished_at' => now()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Models\PhaseRun;
use App\Models\Task;

class WorkflowService
{
    /**
     * Create a PhaseRun and mark the task as running for the given phase.
     *
     * @param  string|null  $model  the resolved model id for this phase, persisted
     *                              so cost analysis can attribute spend per model
     */
    public function startPhase(Task $task, string $phase, ?string $model = null): PhaseRun
    {
        $task->update([
            'current_phase' => $phase,
            'current_status' => PhaseStatus::Running,
        ]);

        return PhaseRun::create([
            'task_id' => $task->id,
            'phase' => $phase,
            'iteration' => $task->phaseRuns()->where('phase', $phase)->count() + 1,
            'status' => PhaseStatus::Running,
            'started_at' => now(),
            'model' => ($model !== null && $model !== '') ? $model : null,
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
            'current_status' => PhaseStatus::Running,
        ]);
    }

    /**
     * Advance workflow_status after a phase completes. Pure state transition —
     * the follow-up side-effects (auto-push, demo teardown, issue notification)
     * are carried out by listeners on the PhaseCompleted event that
     * TaskService::completePhase fires right after this.
     */
    public function completePhase(Task $task, string $phase, PhaseStatus $phaseStatus): void
    {
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
            ->where('started_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'finished_at' => now()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\DemoStatus;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Jobs\StopDemoJob;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Services\IssueTracker\IssueCommentNotifier;

class WorkflowService
{
    /**
     * Create a PhaseRun and mark the task as running for the given phase.
     */
    public function startPhase(Task $task, string $phase): PhaseRun
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
     * Advance workflow_status after a phase completes.
     * Canonical implementation — Task::advanceWorkflow() delegates here.
     */
    public function completePhase(Task $task, string $phase, PhaseStatus $phaseStatus): void
    {
        $next = WorkflowStatus::afterPhase($phase, $phaseStatus);
        if ($next !== null) {
            $task->update(['workflow_status' => $next]);
        }

        // After a successful implement, optionally chain into push. The
        // workflow_status above already reflects ImplementCompleted; while
        // push runs, current_phase/current_status carry the live progress
        // and push completion will advance workflow_status to InReview.
        if ($phase === 'implement'
            && $phaseStatus === PhaseStatus::Completed
            && $task->repoProfile?->auto_pr) {
            RunPhaseJob::dispatch($task->id, 'push');
        }

        // Once the PR is created, tear the live demo down — it was a pre-PR
        // preview. It stays restartable anytime from the detail view (M6).
        if ($phase === 'push' && $phaseStatus === PhaseStatus::Completed) {
            $demo = $task->currentDemo();
            if ($demo !== null && in_array($demo->status, [DemoStatus::Building, DemoStatus::Live], true)) {
                StopDemoJob::dispatch($task->id);
            }
        }

        // Notify the external issue tracker (if this task was imported from one).
        // Errors are swallowed inside the notifier — the workflow must never stall.
        app(IssueCommentNotifier::class)->notifyPhaseCompletion($task, $phase, $phaseStatus->value);
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

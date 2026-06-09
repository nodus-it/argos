<?php

declare(strict_types=1);

namespace App\Testing;

use App\Enums\PhaseStatus;
use App\Models\Task;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\WorkflowService;
use Illuminate\Support\Str;

/**
 * Browser-E2E fake phase runner: drives each phase to a deterministic SUCCESS
 * without `docker run`, image builds, or volume I/O. Bound in place of the real
 * PhaseRunner by E2eFakeServiceProvider (env-gated, never in production).
 *
 * It reuses the real WorkflowService so phase-run creation and the
 * workflow_status transitions (WorkflowStatus::afterPhase) stay identical to a
 * real run — only the worker execution and artifact production are faked.
 */
class FakePhaseRunner extends PhaseRunner
{
    public function runBlocking(Task $task, string $phase, array $flags = []): void
    {
        $service = app(WorkflowService::class);
        $phaseRun = $service->startPhase($task, $phase);

        $phaseRunUpdate = [
            'status' => PhaseStatus::Completed,
            'finished_at' => now(),
            'exit_code' => 0,
            'cost_usd' => 0,
        ];
        $taskUpdate = [];

        if ($phase === 'concept') {
            $conceptMd = "# Concept (E2E fake)\n\nDeterministic concept produced by the browser-E2E fake mode — no worker ran.";
            $phaseRunUpdate['concept_md'] = $conceptMd;
            $taskUpdate['concept_md'] = $conceptMd;
            if ($task->feature_branch === null || $task->feature_branch === '') {
                $taskUpdate['feature_branch'] = 'feat/'.Str::slug($task->name);
            }
        } elseif ($phase === 'implement') {
            $nontechnical = 'E2E fake: implemented the requested change.';
            $technical = 'E2E fake: changes applied deterministically; no docker run.';
            $phaseRunUpdate['implement_summary_nontechnical'] = $nontechnical;
            $phaseRunUpdate['implement_summary_technical'] = $technical;
            $taskUpdate['implement_summary_nontechnical'] = $nontechnical;
            $taskUpdate['implement_summary_technical'] = $technical;
        } elseif ($phase === 'push') {
            $taskUpdate['pr_url'] = 'https://example.test/argos-e2e/demo-app/pull/1';
        }

        $phaseRun->update($phaseRunUpdate);
        if ($taskUpdate !== []) {
            $task->update($taskUpdate);
        }

        $task->update([
            'current_phase' => $phase,
            'current_status' => PhaseStatus::Completed,
        ]);

        $service->completePhase($task, $phase, PhaseStatus::Completed);
    }
}

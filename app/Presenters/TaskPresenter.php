<?php

declare(strict_types=1);

namespace App\Presenters;

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Models\Task;
use App\Support\Workflow\TaskStage;

/**
 * Display-layer derivations for a Task: the status label/colour, the badge
 * state, and the phase rail. Kept out of the model so it stays a persistence
 * concern. Reach it via Task::presenter().
 */
class TaskPresenter
{
    public function __construct(private readonly Task $task) {}

    public function statusLabel(): string
    {
        if ($this->isConceptPaused()) {
            return __('tasks.stage.concept_paused');
        }

        if ($this->isWaitingForWorker()) {
            return match ($this->task->workflow_status) {
                WorkflowStatus::ConceptRunning => __('tasks.statuses.waiting.concept'),
                default => __('tasks.statuses.waiting.implement'),
            };
        }

        return $this->task->workflow_status->label();
    }

    public function statusColor(): string
    {
        if ($this->isConceptPaused()) {
            return WorkflowStatus::ImplementPaused->color();
        }

        if ($this->isWaitingForWorker()) {
            return 'info';
        }

        return $this->task->workflow_status->color();
    }

    /**
     * Map the workflow status onto the redesign's five badge states
     * (running | waiting | success | failed | draft) for <x-argos.badge>.
     * Semantic, not colour-derived. See docs/design/argos/ARGOS_REDESIGN.md §5.1.
     */
    public function badgeStatus(): string
    {
        if ($this->isConceptPaused()) {
            return 'waiting';
        }

        if ($this->isWaitingForWorker()) {
            return 'running';
        }

        return match ($this->task->workflow_status) {
            WorkflowStatus::Draft => 'draft',
            WorkflowStatus::ConceptRunning, WorkflowStatus::ImplementRunning => 'running',
            WorkflowStatus::ConceptReview, WorkflowStatus::ImplementPaused, WorkflowStatus::InReview => 'waiting',
            WorkflowStatus::ImplementCompleted, WorkflowStatus::Completed => 'success',
            WorkflowStatus::Failed, WorkflowStatus::Aborted => 'failed',
        };
    }

    /**
     * Build the 5-node phase rail (Draft → Concept → Implement → Push → Review)
     * for <x-argos.phase-rail>, deriving each node's state
     * (done | active | wait | fail | todo) from the presentation stage.
     *
     * Driving this off TaskStage (not the raw workflow_status) is what makes the
     * push phase highlight Push rather than Implement — push runs under
     * workflow_status=ImplementRunning with current_phase=push — and what makes a
     * review/paused state flag the *finished* phase as "wait" instead of pulsing
     * the next one. See docs/design/argos/ARGOS_REDESIGN.md §5.5.
     *
     * @return list<array{phase: string, state: string}>
     */
    public function phaseRail(): array
    {
        $nodes = ['draft', 'concept', 'implement', 'push', 'review'];
        $states = array_fill_keys($nodes, 'todo');

        // [done nodes, active node, its state] per stage. Queued and running
        // both render as 'active' (the node pulses); paused/review render the
        // node as 'wait' (it needs the user); failed renders 'fail'.
        [$done, $active, $state] = match (TaskStage::for($this->task)) {
            TaskStage::Draft => [[], 'draft', 'active'],

            TaskStage::ConceptQueued, TaskStage::ConceptRunning => [['draft'], 'concept', 'active'],
            TaskStage::ConceptPaused, TaskStage::ConceptReview => [['draft'], 'concept', 'wait'],
            TaskStage::ConceptFailed => [['draft'], 'concept', 'fail'],

            TaskStage::ImplementQueued, TaskStage::ImplementRunning => [['draft', 'concept'], 'implement', 'active'],
            TaskStage::ImplementPaused, TaskStage::ImplementReview => [['draft', 'concept'], 'implement', 'wait'],
            TaskStage::ImplementFailed => [['draft', 'concept'], 'implement', 'fail'],

            TaskStage::PushQueued, TaskStage::PushRunning => [['draft', 'concept', 'implement'], 'push', 'active'],
            TaskStage::PushFailed => [['draft', 'concept', 'implement'], 'push', 'fail'],

            TaskStage::Review => [['draft', 'concept', 'implement', 'push'], 'review', 'wait'],
            TaskStage::Done => [$nodes, '', ''],

            // Aborted is terminal but incomplete — no node is done, active, or
            // highlighted; the rail reads as a neutral stopped state.
            TaskStage::Aborted => [[], '', ''],
        };

        foreach ($done as $d) {
            $states[$d] = 'done';
        }
        if ($active !== '') {
            $states[$active] = $state;
        }

        return array_map(static fn (string $n): array => ['phase' => $n, 'state' => $states[$n]], $nodes);
    }

    /**
     * Whether the task is queued for a worker that has not picked it up yet
     * (running workflow status, but the phase itself is still pending).
     */
    public function isWaitingForWorker(): bool
    {
        return $this->task->current_status === PhaseStatus::Pending
            && in_array($this->task->workflow_status, [WorkflowStatus::ConceptRunning, WorkflowStatus::ImplementRunning], true);
    }

    /**
     * A paused concept run has no dedicated workflow_status (it stays
     * ConceptRunning with current_status=Paused, unlike ImplementPaused which is
     * its own status). Without this the overview shows it as plain "running" and
     * the pause is invisible — the gap the implement side does not have.
     */
    private function isConceptPaused(): bool
    {
        return $this->task->workflow_status === WorkflowStatus::ConceptRunning
            && $this->task->current_status === PhaseStatus::Paused;
    }
}

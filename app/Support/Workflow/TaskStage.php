<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Models\Task;

/**
 * The single presentation state of a task, derived from the persisted
 * workflow_status + current_status + current_phase triple.
 *
 * Whereas WorkflowStatus is the persisted state machine, TaskStage is the
 * UI-facing collapse of it: it distinguishes "queued / waiting for worker"
 * (current_status = pending) from "running" (current_status = running), folds
 * paused/blocked/failed phases into explicit stages, and resolves the fact
 * that the push phase runs under workflow_status = ImplementRunning with
 * current_phase = push.
 *
 * One stage drives everything in the detail view: the status banner, which
 * respond-dock variant is shown, and which header actions are offered.
 */
enum TaskStage: string
{
    case Draft = 'draft';

    case ConceptQueued = 'concept_queued';
    case ConceptRunning = 'concept_running';
    case ConceptPaused = 'concept_paused';
    case ConceptReview = 'concept_review';
    case ConceptFailed = 'concept_failed';

    case ImplementQueued = 'implement_queued';
    case ImplementRunning = 'implement_running';
    case ImplementPaused = 'implement_paused';
    case ImplementReview = 'implement_review';
    case ImplementFailed = 'implement_failed';

    case PushQueued = 'push_queued';
    case PushRunning = 'push_running';
    case PushFailed = 'push_failed';

    /** PR created, awaiting final completion (Abgeschlossen-Modus). */
    case Review = 'review';

    case Done = 'done';

    /** Manually aborted — terminal, read-only (no dock, no phase controls). */
    case Aborted = 'aborted';

    /**
     * Resolve the presentation stage for a task from its persisted state.
     */
    public static function for(Task $task): self
    {
        $ws = $task->workflow_status;
        $cs = $task->current_status;
        $phase = $task->current_phase?->value;

        return match ($ws) {
            WorkflowStatus::Completed => self::Done,
            WorkflowStatus::Aborted => self::Aborted,
            WorkflowStatus::InReview => self::Review,
            WorkflowStatus::ConceptReview => self::ConceptReview,
            WorkflowStatus::ImplementCompleted => self::ImplementReview,
            WorkflowStatus::ImplementPaused => self::ImplementPaused,
            WorkflowStatus::Failed => match ($phase) {
                'implement' => self::ImplementFailed,
                'push' => self::PushFailed,
                default => self::ConceptFailed,
            },
            WorkflowStatus::ConceptRunning => match (true) {
                $cs === PhaseStatus::Paused => self::ConceptPaused,
                self::isQueuedStatus($cs) => self::ConceptQueued,
                default => self::ConceptRunning,
            },
            // The push phase runs under ImplementRunning with current_phase=push.
            WorkflowStatus::ImplementRunning => self::resolveImplementRunning($cs, $phase === 'push'),
            WorkflowStatus::Draft => self::Draft,
        };
    }

    private static function resolveImplementRunning(?PhaseStatus $cs, bool $isPush): self
    {
        // A lock-blocked implement run keeps workflow_status=ImplementRunning but
        // is an error state needing the force-unlock recovery action.
        if ($cs === PhaseStatus::LockBlocked) {
            return self::ImplementFailed;
        }

        if (self::isQueuedStatus($cs)) {
            return $isPush ? self::PushQueued : self::ImplementQueued;
        }

        return $isPush ? self::PushRunning : self::ImplementRunning;
    }

    /** Pending = not yet picked up; rate-limited = re-scheduled — both "waiting for worker". */
    private static function isQueuedStatus(?PhaseStatus $cs): bool
    {
        return $cs === PhaseStatus::Pending || $cs === PhaseStatus::RateLimited || $cs === null;
    }

    /** The phase this stage belongs to, or null for draft/done. */
    public function phase(): ?Phase
    {
        return match ($this) {
            self::ConceptQueued, self::ConceptRunning, self::ConceptPaused,
            self::ConceptReview, self::ConceptFailed => Phase::Concept,
            self::ImplementQueued, self::ImplementRunning, self::ImplementPaused,
            self::ImplementReview, self::ImplementFailed => Phase::Implement,
            self::PushQueued, self::PushRunning, self::PushFailed => Phase::Push,
            self::Draft, self::Review, self::Done, self::Aborted => null,
        };
    }

    /** The worker is actively executing a phase right now. */
    public function isRunning(): bool
    {
        return in_array($this, [self::ConceptRunning, self::ImplementRunning, self::PushRunning], true);
    }

    /** A phase job is dispatched but not yet picked up (waiting for the worker). */
    public function isQueued(): bool
    {
        return in_array($this, [self::ConceptQueued, self::ImplementQueued, self::PushQueued], true);
    }

    /** A phase stopped at the turn limit and waits for the user to resume. */
    public function isPaused(): bool
    {
        return in_array($this, [self::ConceptPaused, self::ImplementPaused], true);
    }

    /** A phase ended in an error the user must resolve. */
    public function isFailed(): bool
    {
        return in_array($this, [self::ConceptFailed, self::ImplementFailed, self::PushFailed], true);
    }

    /** System is working (running or queued) — the respond dock is hidden. */
    public function isBusy(): bool
    {
        return $this->isRunning() || $this->isQueued();
    }

    /** Waiting on a human decision (review, paused, or PR awaiting completion). */
    public function isWaitingForUser(): bool
    {
        return in_array($this, [
            self::ConceptReview, self::ImplementReview, self::Review,
            self::ConceptPaused, self::ImplementPaused,
        ], true);
    }

    /** The task has progressed past the concept phase (concept is locked). */
    public function isPastConcept(): bool
    {
        return in_array($this, [
            self::ImplementQueued, self::ImplementRunning, self::ImplementPaused,
            self::ImplementReview, self::ImplementFailed,
            self::PushQueued, self::PushRunning, self::PushFailed,
            self::Review, self::Done,
        ], true);
    }

    /**
     * Which respond-dock variant to render, or 'none' to hide it. Drives both
     * the visible buttons and the hint copy in the composer. Phase
     * advancement lives here, not in the header (M4). Paused states keep the
     * header continue action (it carries the max-turns modal); review-after-PR
     * and done show no dock (Abgeschlossen-Modus).
     */
    public function dockMode(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::ConceptReview => 'concept',
            self::ImplementReview => 'implement',
            self::ConceptFailed => 'retry_concept',
            self::ImplementFailed => 'retry_implement',
            self::PushFailed => 'retry_push',
            default => 'none',
        };
    }

    /** Semantic banner state for <x-argos.status-banner>. */
    public function bannerState(): string
    {
        return match (true) {
            $this->isRunning() => 'running',
            $this->isQueued() => 'queued',
            $this->isFailed() => 'failed',
            $this->isPaused() => 'paused',
            $this === self::ConceptReview, $this === self::ImplementReview, $this === self::Review => 'waiting',
            $this === self::Done => 'done',
            $this === self::Aborted => 'aborted',
            default => 'draft',
        };
    }

    public function label(): string
    {
        return __('tasks.stage.'.$this->value);
    }
}

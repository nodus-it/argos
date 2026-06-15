<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkflowStatus: string
{
    case Draft = 'draft';
    case ConceptRunning = 'concept_running';
    case ConceptReview = 'concept_review';
    case ImplementRunning = 'implement_running';
    case ImplementPaused = 'implement_paused';
    case ImplementCompleted = 'implement_completed';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Failed = 'failed';
    case Aborted = 'aborted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('enums.workflow_status.draft'),
            self::ConceptRunning => __('enums.workflow_status.concept_running'),
            self::ConceptReview => __('enums.workflow_status.concept_review'),
            self::ImplementRunning => __('enums.workflow_status.implement_running'),
            self::ImplementPaused => __('enums.workflow_status.implement_paused'),
            self::ImplementCompleted => __('enums.workflow_status.implement_completed'),
            self::InReview => __('enums.workflow_status.in_review'),
            self::Completed => __('enums.workflow_status.completed'),
            self::Failed => __('enums.workflow_status.failed'),
            self::Aborted => __('enums.workflow_status.aborted'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ConceptRunning => 'warning',
            self::ConceptReview => 'info',
            self::ImplementRunning => 'warning',
            self::ImplementPaused => 'warning',
            self::ImplementCompleted => 'success',
            self::InReview => 'primary',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Aborted => 'gray',
        };
    }

    /**
     * Whether a phase can be retried from this workflow status.
     */
    public function canRetryPhase(string $phase): bool
    {
        return match ($this) {
            self::Failed => true,
            self::ImplementPaused => $phase === 'implement',
            default => false,
        };
    }

    /**
     * The workflow status to transition to when retrying a failed or paused phase.
     */
    public function retryingPhase(string $phase): self
    {
        return match ($phase) {
            'concept' => self::ConceptRunning,
            'implement', 'push' => self::ImplementRunning,
            'respond' => self::InReview,
            default => $this,
        };
    }

    /**
     * Advance the workflow after a phase completes.
     * Returns the next status or null if no change is needed.
     */
    public static function afterPhase(string $phase, PhaseStatus $phaseStatus): ?self
    {
        return match ([$phase, $phaseStatus]) {
            ['concept', PhaseStatus::Completed] => self::ConceptReview,
            ['concept', PhaseStatus::Failed] => self::Failed,
            ['concept', PhaseStatus::QualityGateFailed] => self::Failed,
            ['implement', PhaseStatus::Completed] => self::ImplementCompleted,
            ['implement', PhaseStatus::Failed] => self::Failed,
            ['implement', PhaseStatus::QualityGateFailed] => self::Failed,
            ['implement', PhaseStatus::Paused] => self::ImplementPaused,
            ['push', PhaseStatus::Completed] => self::InReview,
            ['push', PhaseStatus::Failed] => self::Failed,
            ['respond', PhaseStatus::Completed] => self::InReview,
            ['respond', PhaseStatus::Failed] => self::Failed,
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkflowStatus: string
{
    case Draft = 'draft';
    case ConceptRunning = 'concept_running';
    case ConceptReview = 'concept_review';
    case ImplementRunning = 'implement_running';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::ConceptRunning => 'Konzept läuft',
            self::ConceptReview => 'Konzept bereit',
            self::ImplementRunning => 'Implementierung läuft',
            self::InReview => 'In Review',
            self::Completed => 'Abgeschlossen',
            self::Failed => 'Fehlgeschlagen',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ConceptRunning => 'warning',
            self::ConceptReview => 'info',
            self::ImplementRunning => 'warning',
            self::InReview => 'primary',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    /**
     * Advance the workflow after a phase completes.
     * Returns the next status or null if no change is needed.
     */
    public static function afterPhase(string $phase, string $phaseStatus): ?self
    {
        return match ([$phase, $phaseStatus]) {
            ['concept', 'completed'] => self::ConceptReview,
            ['concept', 'failed'] => self::Failed,
            ['concept', 'quality_gate_failed'] => self::Failed,
            ['implement', 'failed'] => self::Failed,
            ['implement', 'quality_gate_failed'] => self::Failed,
            ['push', 'completed'] => self::InReview,
            ['push', 'failed'] => self::Failed,
            ['respond', 'completed'] => self::InReview,
            ['respond', 'failed'] => self::Failed,
            default => null,
        };
    }
}

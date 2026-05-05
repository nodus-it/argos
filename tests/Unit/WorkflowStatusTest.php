<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use Tests\TestCase;

class WorkflowStatusTest extends TestCase
{
    // --- afterPhase ---

    public function test_concept_completed_yields_concept_review(): void
    {
        $this->assertSame(WorkflowStatus::ConceptReview, WorkflowStatus::afterPhase('concept', PhaseStatus::Completed));
    }

    public function test_concept_failed_yields_failed(): void
    {
        $this->assertSame(WorkflowStatus::Failed, WorkflowStatus::afterPhase('concept', PhaseStatus::Failed));
    }

    public function test_concept_quality_gate_failed_yields_failed(): void
    {
        $this->assertSame(WorkflowStatus::Failed, WorkflowStatus::afterPhase('concept', PhaseStatus::QualityGateFailed));
    }

    public function test_implement_failed_yields_failed(): void
    {
        $this->assertSame(WorkflowStatus::Failed, WorkflowStatus::afterPhase('implement', PhaseStatus::Failed));
    }

    public function test_implement_quality_gate_failed_yields_failed(): void
    {
        $this->assertSame(WorkflowStatus::Failed, WorkflowStatus::afterPhase('implement', PhaseStatus::QualityGateFailed));
    }

    public function test_push_completed_yields_in_review(): void
    {
        $this->assertSame(WorkflowStatus::InReview, WorkflowStatus::afterPhase('push', PhaseStatus::Completed));
    }

    public function test_push_failed_yields_failed(): void
    {
        $this->assertSame(WorkflowStatus::Failed, WorkflowStatus::afterPhase('push', PhaseStatus::Failed));
    }

    public function test_respond_completed_yields_in_review(): void
    {
        $this->assertSame(WorkflowStatus::InReview, WorkflowStatus::afterPhase('respond', PhaseStatus::Completed));
    }

    public function test_respond_failed_yields_failed(): void
    {
        $this->assertSame(WorkflowStatus::Failed, WorkflowStatus::afterPhase('respond', PhaseStatus::Failed));
    }

    public function test_implement_completed_returns_null(): void
    {
        // implement completed is handled by WorkflowService::completePhase, not afterPhase
        $this->assertNull(WorkflowStatus::afterPhase('implement', PhaseStatus::Completed));
    }

    public function test_unknown_combination_returns_null(): void
    {
        $this->assertNull(WorkflowStatus::afterPhase('unknown', PhaseStatus::Completed));
        $this->assertNull(WorkflowStatus::afterPhase('concept', PhaseStatus::NoChanges));
    }

    // --- label ---

    public function test_all_cases_have_non_empty_label(): void
    {
        foreach (WorkflowStatus::cases() as $case) {
            $this->assertNotEmpty($case->label(), "Label for {$case->name} is empty");
        }
    }

    // --- color ---

    public function test_all_cases_have_valid_filament_color(): void
    {
        $valid = ['gray', 'warning', 'info', 'primary', 'success', 'danger'];

        foreach (WorkflowStatus::cases() as $case) {
            $this->assertContains(
                $case->color(),
                $valid,
                "Color '{$case->color()}' for {$case->name} is not a valid Filament color"
            );
        }
    }
}

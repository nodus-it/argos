<?php

declare(strict_types=1);

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Models\Task;
use App\Support\Workflow\TaskStage;
use Tests\TestCase;

// Bind to the Laravel TestCase so __() (translator) is available; Unit tests
// otherwise run on the bare PHPUnit base without an app container.
uses(TestCase::class);

/**
 * Build an in-memory Task with the given persisted-state triple. No DB —
 * TaskStage::for only reads workflow_status / current_status / current_phase.
 */
function stageTask(WorkflowStatus $ws, ?PhaseStatus $cs = null, ?Phase $phase = null): Task
{
    return (new Task)->forceFill([
        'workflow_status' => $ws,
        'current_status' => $cs,
        'current_phase' => $phase,
    ]);
}

dataset('stage_mapping', [
    'draft' => [WorkflowStatus::Draft, null, null, TaskStage::Draft],

    'concept queued (pending)' => [WorkflowStatus::ConceptRunning, PhaseStatus::Pending, Phase::Concept, TaskStage::ConceptQueued],
    'concept queued (rate limited)' => [WorkflowStatus::ConceptRunning, PhaseStatus::RateLimited, Phase::Concept, TaskStage::ConceptQueued],
    'concept running' => [WorkflowStatus::ConceptRunning, PhaseStatus::Running, Phase::Concept, TaskStage::ConceptRunning],
    'concept paused' => [WorkflowStatus::ConceptRunning, PhaseStatus::Paused, Phase::Concept, TaskStage::ConceptPaused],
    'concept review' => [WorkflowStatus::ConceptReview, PhaseStatus::Completed, Phase::Concept, TaskStage::ConceptReview],

    'implement queued' => [WorkflowStatus::ImplementRunning, PhaseStatus::Pending, Phase::Implement, TaskStage::ImplementQueued],
    'implement running' => [WorkflowStatus::ImplementRunning, PhaseStatus::Running, Phase::Implement, TaskStage::ImplementRunning],
    'implement paused' => [WorkflowStatus::ImplementPaused, PhaseStatus::Paused, Phase::Implement, TaskStage::ImplementPaused],
    'implement review' => [WorkflowStatus::ImplementCompleted, PhaseStatus::Completed, Phase::Implement, TaskStage::ImplementReview],
    'implement lock-blocked is a failure' => [WorkflowStatus::ImplementRunning, PhaseStatus::LockBlocked, Phase::Implement, TaskStage::ImplementFailed],

    // The push phase runs under ImplementRunning with current_phase=push.
    'push queued' => [WorkflowStatus::ImplementRunning, PhaseStatus::Pending, Phase::Push, TaskStage::PushQueued],
    'push running' => [WorkflowStatus::ImplementRunning, PhaseStatus::Running, Phase::Push, TaskStage::PushRunning],

    'review (pr created)' => [WorkflowStatus::InReview, PhaseStatus::Completed, Phase::Push, TaskStage::Review],
    'done' => [WorkflowStatus::Completed, PhaseStatus::Completed, Phase::Push, TaskStage::Done],

    'failed concept' => [WorkflowStatus::Failed, PhaseStatus::Failed, Phase::Concept, TaskStage::ConceptFailed],
    'failed implement' => [WorkflowStatus::Failed, PhaseStatus::Failed, Phase::Implement, TaskStage::ImplementFailed],
    'failed push' => [WorkflowStatus::Failed, PhaseStatus::Failed, Phase::Push, TaskStage::PushFailed],
    'failed without phase falls back to concept' => [WorkflowStatus::Failed, PhaseStatus::Failed, null, TaskStage::ConceptFailed],

    'aborted (terminal, ignores phase)' => [WorkflowStatus::Aborted, PhaseStatus::Failed, Phase::Implement, TaskStage::Aborted],
]);

it('resolves the presentation stage from the persisted state triple', function (
    WorkflowStatus $ws,
    ?PhaseStatus $cs,
    ?Phase $phase,
    TaskStage $expected,
) {
    expect(TaskStage::for(stageTask($ws, $cs, $phase)))->toBe($expected);
})->with('stage_mapping');

it('treats running stages as busy and hides the dock', function () {
    foreach ([TaskStage::ConceptRunning, TaskStage::ImplementRunning, TaskStage::PushRunning] as $stage) {
        expect($stage->isRunning())->toBeTrue()
            ->and($stage->isBusy())->toBeTrue()
            ->and($stage->dockMode())->toBe('none');
    }
});

it('treats queued stages as waiting for the worker', function () {
    foreach ([TaskStage::ConceptQueued, TaskStage::ImplementQueued, TaskStage::PushQueued] as $stage) {
        expect($stage->isQueued())->toBeTrue()
            ->and($stage->isBusy())->toBeTrue()
            ->and($stage->isRunning())->toBeFalse();
    }
});

it('exposes review docks for the human-decision stages', function () {
    expect(TaskStage::ConceptReview->dockMode())->toBe('concept')
        ->and(TaskStage::ImplementReview->dockMode())->toBe('implement')
        ->and(TaskStage::Review->dockMode())->toBe('none')
        ->and(TaskStage::ConceptReview->isWaitingForUser())->toBeTrue()
        ->and(TaskStage::ImplementReview->isWaitingForUser())->toBeTrue();
});

it('exposes start and retry docks', function () {
    expect(TaskStage::Draft->dockMode())->toBe('draft')
        ->and(TaskStage::ConceptFailed->dockMode())->toBe('retry_concept')
        ->and(TaskStage::ImplementFailed->dockMode())->toBe('retry_implement')
        ->and(TaskStage::PushFailed->dockMode())->toBe('retry_push')
        ->and(TaskStage::Done->dockMode())->toBe('none')
        ->and(TaskStage::ConceptPaused->dockMode())->toBe('none')
        ->and(TaskStage::ImplementPaused->dockMode())->toBe('none');
});

it('knows once the concept phase is locked', function () {
    expect(TaskStage::Draft->isPastConcept())->toBeFalse()
        ->and(TaskStage::ConceptReview->isPastConcept())->toBeFalse()
        ->and(TaskStage::ConceptRunning->isPastConcept())->toBeFalse()
        ->and(TaskStage::ImplementRunning->isPastConcept())->toBeTrue()
        ->and(TaskStage::ImplementReview->isPastConcept())->toBeTrue()
        ->and(TaskStage::PushRunning->isPastConcept())->toBeTrue()
        ->and(TaskStage::Review->isPastConcept())->toBeTrue()
        ->and(TaskStage::Done->isPastConcept())->toBeTrue();
});

it('reports a banner state and a non-empty label for every case', function () {
    $validBannerStates = ['running', 'queued', 'failed', 'paused', 'waiting', 'done', 'aborted', 'draft'];

    foreach (TaskStage::cases() as $stage) {
        expect($validBannerStates)->toContain($stage->bannerState())
            ->and($stage->label())->not->toBe('tasks.stage.'.$stage->value)
            ->and($stage->label())->not->toBe('');
    }
});

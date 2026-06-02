<?php

declare(strict_types=1);

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Models\Task;
use App\Providers\E2eFakeServiceProvider;
use App\Services\Workflow\PhaseRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->register(E2eFakeServiceProvider::class);
});

it('drives concept to ConceptReview without docker', function (): void {
    $task = Task::factory()->create([
        'workflow_status' => WorkflowStatus::Draft,
        'feature_branch' => null,
        'concept_md' => null,
    ]);

    app(PhaseRunner::class)->runBlocking($task, 'concept');
    $task->refresh();

    expect($task->workflow_status)->toBe(WorkflowStatus::ConceptReview)
        ->and($task->current_status)->toBe(PhaseStatus::Completed)
        ->and($task->concept_md)->not->toBeNull()
        ->and($task->feature_branch)->not->toBeNull();

    expect(
        $task->phaseRuns()
            ->where('phase', 'concept')
            ->where('status', PhaseStatus::Completed->value)
            ->exists()
    )->toBeTrue();
});

it('drives implement to ImplementCompleted with summaries', function (): void {
    $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);

    app(PhaseRunner::class)->runBlocking($task, 'implement');
    $task->refresh();

    expect($task->workflow_status)->toBe(WorkflowStatus::ImplementCompleted)
        ->and($task->current_status)->toBe(PhaseStatus::Completed)
        ->and($task->implement_summary_nontechnical)->not->toBeNull()
        ->and($task->implement_summary_technical)->not->toBeNull();
});

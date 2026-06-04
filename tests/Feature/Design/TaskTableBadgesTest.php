<?php

declare(strict_types=1);

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the workflow badge and phase chip in the tasks table', function (): void {
    $this->actingAs(User::factory()->create());
    Task::factory()->create([
        'workflow_status' => WorkflowStatus::ConceptReview,
        'current_phase' => 'concept',
    ]);

    Livewire::test(CurrentTasksWidget::class)
        ->assertOk()
        ->assertSee('class="badge badge-waiting', false)
        ->assertSee('class="chip', false);
});

it('maps workflow statuses onto the five badge states', function (): void {
    expect(Task::factory()->make(['workflow_status' => WorkflowStatus::Draft])->displayBadgeStatus())->toBe('draft')
        ->and(Task::factory()->make(['workflow_status' => WorkflowStatus::ConceptRunning])->displayBadgeStatus())->toBe('running')
        ->and(Task::factory()->make(['workflow_status' => WorkflowStatus::InReview])->displayBadgeStatus())->toBe('waiting')
        ->and(Task::factory()->make(['workflow_status' => WorkflowStatus::Completed])->displayBadgeStatus())->toBe('success')
        ->and(Task::factory()->make(['workflow_status' => WorkflowStatus::Failed])->displayBadgeStatus())->toBe('failed');
});

it('surfaces a paused concept run in the overview like implement-paused', function (): void {
    // Concept-paused has no own workflow_status — it must still read as a
    // waiting state in the table, not as a plain running concept.
    $task = Task::factory()->make([
        'workflow_status' => WorkflowStatus::ConceptRunning,
        'current_phase' => 'concept',
        'current_status' => PhaseStatus::Paused,
    ]);

    expect($task->displayBadgeStatus())->toBe('waiting')
        ->and($task->displayStatusLabel())->toBe(__('tasks.stage.concept_paused'))
        ->and($task->displayStatusColor())->toBe(WorkflowStatus::ImplementPaused->color());
});

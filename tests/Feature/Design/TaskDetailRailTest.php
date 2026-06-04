<?php

declare(strict_types=1);

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Models\Task;
use App\Models\User;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\StateReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    Process::fake();

    $this->mock(StateReader::class, function ($mock): void {
        $mock->shouldReceive('syncToDb')->andReturn(null);
        $mock->shouldReceive('readNotesHistory')->andReturn([]);
        $mock->shouldReceive('readConceptHistory')->andReturn([]);
        $mock->shouldReceive('readImplementHistory')->andReturn([]);
        $mock->shouldReceive('readImplementNotesHistory')->andReturn([]);
        $mock->shouldReceive('listLogIterations')->andReturn([]);
    });
    $this->mock(PhaseRunner::class, fn ($mock) => $mock->shouldIgnoreMissing());
});

it('renders the phase rail on the task detail page', function (): void {
    $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);

    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertSee('class="rail"', false)
        ->assertSee('rail-node', false);
});

it('derives phase rail node states from the workflow status', function (): void {
    $draft = collect(Task::factory()->make(['workflow_status' => WorkflowStatus::Draft])->phaseRail())
        ->keyBy('phase');
    expect($draft['draft']['state'])->toBe('active')
        ->and($draft['concept']['state'])->toBe('todo');

    $review = collect(Task::factory()->make(['workflow_status' => WorkflowStatus::ConceptReview])->phaseRail())
        ->keyBy('phase');
    expect($review['draft']['state'])->toBe('done')
        ->and($review['concept']['state'])->toBe('wait');

    $done = collect(Task::factory()->make(['workflow_status' => WorkflowStatus::Completed])->phaseRail());
    expect($done->every(fn (array $n): bool => $n['state'] === 'done'))->toBeTrue();

    $failed = collect(Task::factory()->make([
        'workflow_status' => WorkflowStatus::Failed,
        'current_phase' => 'implement',
    ])->phaseRail())->keyBy('phase');
    expect($failed['implement']['state'])->toBe('fail')
        ->and($failed['concept']['state'])->toBe('done');
});

it('highlights push (not implement) while the push phase runs', function (): void {
    // Push runs under workflow_status=ImplementRunning with current_phase=push.
    $rail = collect(Task::factory()->make([
        'workflow_status' => WorkflowStatus::ImplementRunning,
        'current_phase' => 'push',
        'current_status' => 'running',
    ])->phaseRail())->keyBy('phase');

    expect($rail['implement']['state'])->toBe('done')
        ->and($rail['push']['state'])->toBe('active');
});

it('flags implement as waiting (not push) while the implementation is in review', function (): void {
    $rail = collect(Task::factory()->make([
        'workflow_status' => WorkflowStatus::ImplementCompleted,
        'current_phase' => 'implement',
        'current_status' => 'completed',
    ])->phaseRail())->keyBy('phase');

    expect($rail['implement']['state'])->toBe('wait')
        ->and($rail['push']['state'])->toBe('todo');
});

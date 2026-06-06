<?php

declare(strict_types=1);

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Widgets\DashboardHeroWidget;
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
        $mock->shouldReceive('readStreamLogIteration')->andReturn([]);
    });
    $this->mock(PhaseRunner::class, fn ($mock) => $mock->shouldIgnoreMissing());
});

// ── Dashboard hero ──────────────────────────────────────────────────────────

it('renders the dashboard hero widget with the eye stage and ticker', function (): void {
    Livewire::test(DashboardHeroWidget::class)
        ->assertSuccessful()
        ->assertSee('dh-hero', false)
        ->assertSee('dh-eye-stage', false)
        ->assertSee('dh-term', false);
});

it('dashboard hero widget shows idle text when no phase runs exist', function (): void {
    Livewire::test(DashboardHeroWidget::class)
        ->assertSuccessful()
        ->assertSee('dh-term-body', false);
});

// ── Tasks list hero ─────────────────────────────────────────────────────────

it('list tasks page renders the ph-hero instead of a standard heading', function (): void {
    Livewire::test(ListTasks::class)
        ->assertSuccessful()
        ->assertSee('ph-hero', false)
        ->assertSee('ph-ic', false)
        ->assertDontSee('fi-header-heading');
});

it('tasks list hero shows all three chips', function (): void {
    Livewire::test(ListTasks::class)
        ->assertSuccessful()
        ->assertSee('d-run', false)
        ->assertSee('d-wait', false)
        ->assertSee('d-ok', false);
});

it('tasks list hero has a new-task link', function (): void {
    Livewire::test(ListTasks::class)
        ->assertSuccessful()
        ->assertSee(__('widgets.hero.new_task'));
});

// ── Projects list hero ───────────────────────────────────────────────────────

it('list repo profiles page renders the ph-hero with folder icon', function (): void {
    Livewire::test(ListRepoProfiles::class)
        ->assertSuccessful()
        ->assertSee('ph-hero', false)
        ->assertSee('ph-ic', false)
        ->assertDontSee('fi-header-heading');
});

it('projects list hero has a new-project link', function (): void {
    Livewire::test(ListRepoProfiles::class)
        ->assertSuccessful()
        ->assertSee(__('widgets.hero.new_project'));
});

// ── Task detail hero ─────────────────────────────────────────────────────────

it('task detail hero renders with task name', function (): void {
    $task = Task::factory()->create([
        'name' => 'my-test-task',
        'workflow_status' => WorkflowStatus::Draft,
    ]);

    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertSee('th-hero', false)
        ->assertSee('my-test-task');
});

it('th-live strip is absent for a draft task', function (): void {
    $task = Task::factory()->create(['workflow_status' => WorkflowStatus::Draft]);

    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertDontSee('th-live');
});

it('th-live strip is absent for a completed task', function (): void {
    $task = Task::factory()->create(['workflow_status' => WorkflowStatus::Completed]);

    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertDontSee('th-live');
});

it('th-live strip appears when concept is running', function (): void {
    $task = Task::factory()->create([
        'workflow_status' => WorkflowStatus::ConceptRunning,
        'current_phase' => 'concept',
        'current_status' => 'running',
    ]);

    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertSee('th-live', false);
});

it('th-live strip appears when implement is running', function (): void {
    $task = Task::factory()->create([
        'workflow_status' => WorkflowStatus::ImplementRunning,
        'current_phase' => 'implement',
        'current_status' => 'running',
    ]);

    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertSee('th-live', false);
});

it('no duplicate task name heading when task detail hero is present', function (): void {
    $task = Task::factory()->create([
        'name' => 'hero-test-task',
        'workflow_status' => WorkflowStatus::Draft,
    ]);

    // The standard Filament heading should be empty/absent (hero replaces it)
    Livewire::test(ViewTask::class, ['record' => $task->getKey()])
        ->assertSuccessful()
        ->assertDontSee('fi-header-heading');
});

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Phase;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Models\User;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M4: phase advancement lives in the bottom respond dock, not the header.
 * M5: order is strict — once implement has run, the concept is locked.
 */
final class TaskDockAndOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Bus::fake();
        Process::fake();
    }

    public function test_concept_review_dock_offers_update_and_start_implement(): void
    {
        $task = Task::factory()->conceptReady()->create();
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'concept', 'status' => 'completed', 'concept_md' => '# C']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Update concept')
            ->assertSee('Start implementation');
    }

    public function test_implement_review_dock_offers_refine_and_push(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementCompleted,
            'current_phase' => 'implement',
            'current_status' => 'completed',
        ]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Refine implementation')
            ->assertSee('Create Push & PR');
    }

    public function test_refine_implement_from_review_dock_passes_refine_flag(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementCompleted,
            'current_phase' => 'implement',
            'current_status' => 'completed',
        ]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->set('implementNotes', 'Tweak the spacing')
            ->call('saveImplementNotesAndRevise', true);

        // Refine must NOT reset to the base branch — the worker keys off this flag.
        Bus::assertDispatched(
            RunPhaseJob::class,
            fn ($j) => $j->taskId === $task->id && $j->phase === 'implement' && $j->flags === ['refine' => true],
        );
    }

    public function test_no_dock_while_a_phase_is_running(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_phase' => 'concept',
            'current_status' => 'running',
        ]);
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Start implementation')
            ->assertDontSeeHtml('respond-dock');
    }

    public function test_concept_is_locked_once_implementation_has_run_in_the_ui(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementCompleted,
            'current_phase' => 'implement',
            'current_status' => 'completed',
        ]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->call('startConceptFromDock')
            ->assertNotified();

        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    public function test_task_service_rejects_concept_after_implement(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        $this->expectException(\RuntimeException::class);

        app(TaskService::class)->startPhase($task, Phase::Concept);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The thread renders one entry per phase iteration, interleaved with the
 * feedback that triggered each re-run, so the history is gap-free (M3).
 */
final class TaskThreadIterationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_each_concept_iteration_renders_with_version_and_triggering_feedback(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptReview,
            'current_phase' => 'concept',
            'current_status' => 'completed',
        ]);

        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
            'concept_md' => "# Concept v1\n\nFirst draft.",
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 2,
            'status' => 'completed',
            'concept_md' => "# Concept v2\n\nRevised.",
            'concept_notes' => 'Please also cover the edge case.',
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Concept v1')
            ->assertSee('Concept v2')
            ->assertSee('Your feedback')
            ->assertSee('Please also cover the edge case.');
    }

    public function test_single_iteration_has_no_version_suffix(): void
    {
        $task = Task::factory()->conceptReady()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
            'concept_md' => '# Concept',
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Concept v1');
    }

    public function test_running_phase_renders_a_running_thread_entry(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_phase' => 'concept',
            'current_status' => 'running',
        ]);
        PhaseRun::factory()->running()->create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSeeHtml('feed-node st-run');
    }
}

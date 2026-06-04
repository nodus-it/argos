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
 * The status banner (M1) is the single "what is the system doing right now"
 * header. It must clearly distinguish running / waiting-for-worker / failed.
 */
final class TaskStatusBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_queued_state_shows_waiting_for_worker(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_phase' => 'concept',
            'current_status' => 'pending',
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('waiting for worker')
            ->assertSee('queue');
    }

    public function test_running_state_shows_running_label(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_phase' => 'concept',
            'current_status' => 'running',
        ]);
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Concept running');
    }

    public function test_banner_embeds_the_phase_stepper_as_one_unit(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_phase' => 'implement',
            'current_status' => 'running',
        ]);
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'implement']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            // The unified banner card wraps the stepper + status band …
            ->assertSee('workflow-banner', escape: false)
            // … and the stepper renders the localized phase labels.
            ->assertSee(__('tasks.rail.concept'))
            ->assertSee(__('tasks.rail.implement'));
    }

    public function test_failed_state_surfaces_error_log_and_logs_link(): void
    {
        $task = Task::factory()->failed()->create([
            'current_phase' => 'implement',
        ]);
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'status' => 'failed',
            'error_log' => 'fatal: something went very wrong in the worker',
        ]);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Implementation failed')
            ->assertSee('something went very wrong')
            ->assertSee(route('filament.admin.resources.tasks.logs', ['record' => $task->getKey()]));
    }
}

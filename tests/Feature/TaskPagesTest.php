<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Phase\StateReader;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskConcept;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskDiff;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskLogs;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskRespond;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class TaskPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Bus::fake();
        Process::fake();

        $this->mock(StateReader::class, function ($mock) {
            $mock->shouldReceive('syncToDb')->andReturn(null);
            $mock->shouldReceive('readNotesHistory')->andReturn([]);
            $mock->shouldReceive('readConceptHistory')->andReturn([]);
            $mock->shouldReceive('listLogIterations')->andReturn([]);
        });
    }

    // ── ViewTask ─────────────────────────────────────────────────────────────

    public function test_view_task_renders(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee($task->name);
    }

    public function test_view_task_shows_workflow_badge(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('In Review');
    }

    public function test_view_task_concept_action_dispatches_job(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('concept')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept');
    }

    public function test_view_task_implement_action_dispatches_job(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'concept', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('implement')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement');
    }

    public function test_view_task_push_action_dispatches_job(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('push')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'push');
    }

    public function test_view_task_mark_completed_action(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('markCompleted')
            ->assertNotified();

        $this->assertEquals(WorkflowStatus::Completed, $task->fresh()->workflow_status);
    }

    public function test_mark_completed_hidden_when_already_completed(): void
    {
        $task = Task::factory()->completed()->create();

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('markCompleted');
    }

    public function test_delete_volume_visible_only_when_completed(): void
    {
        $incomplete = Task::factory()->inReview()->create();
        $complete = Task::factory()->completed()->create();

        Livewire::test(ViewTask::class, ['record' => $incomplete->getKey()])
            ->assertActionHidden('deleteVolume');

        Livewire::test(ViewTask::class, ['record' => $complete->getKey()])
            ->assertActionVisible('deleteVolume');
    }

    public function test_phase_action_warns_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('concept')
            ->assertNotified();

        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    // ── ViewTaskConcept ───────────────────────────────────────────────────────

    public function test_concept_page_renders_with_markdown(): void
    {
        $task = Task::factory()->conceptReady()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Test-Konzept Inhalt.');
    }

    public function test_concept_page_start_implement_dispatches_job(): void
    {
        $task = Task::factory()->conceptReady()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->call('startImplement')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement');
        $this->assertEquals(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_concept_page_save_notes(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->call('startEditingNotes')
            ->set('notes', 'Meine Anmerkung')
            ->call('saveNotes')
            ->assertNotified();
    }

    public function test_concept_page_cancel_notes(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->call('startEditingNotes')
            ->set('notes', 'wird verworfen')
            ->call('cancelEditingNotes')
            ->assertSet('editingNotes', false);
    }

    public function test_concept_page_run_concept_again(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskConcept::class, ['record' => $task->getKey()])
            ->callAction('runConcept')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept');
    }

    // ── ViewTaskLogs ─────────────────────────────────────────────────────────

    public function test_logs_page_renders(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskLogs::class, ['record' => $task->getKey()])
            ->assertSuccessful();
    }

    // ── ViewTaskDiff ─────────────────────────────────────────────────────────

    public function test_diff_page_renders(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ViewTaskDiff::class, ['record' => $task->getKey()])
            ->assertSuccessful();
    }

    // ── ViewTaskRespond ───────────────────────────────────────────────────────

    public function test_respond_page_renders(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTaskRespond::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Review-Feedback');
    }

    public function test_respond_submit_feedback_dispatches_job(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTaskRespond::class, ['record' => $task->getKey()])
            ->set('feedback', 'Bitte Methode umbenennen.')
            ->call('submitFeedback')
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'respond');
    }

    public function test_respond_rejects_empty_feedback(): void
    {
        $task = Task::factory()->inReview()->create();

        Livewire::test(ViewTaskRespond::class, ['record' => $task->getKey()])
            ->set('feedback', '')
            ->call('submitFeedback')
            ->assertNotified();

        Bus::assertNotDispatched(RunPhaseJob::class);
    }
}

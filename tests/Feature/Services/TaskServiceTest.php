<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Events\Task\ConceptNotesUpdated;
use App\Events\Task\FeedbackSubmitted;
use App\Events\Task\ImplementNotesUpdated;
use App\Events\Task\PhaseCompleted;
use App\Events\Task\PhaseStarted;
use App\Events\Task\TaskCompleted;
use App\Events\Task\TaskCreated;
use App\Events\Task\TaskDeleted;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\RunResourceReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Process::fake();
        $this->service = app(TaskService::class);
    }

    // ── createTask ────────────────────────────────────────────────────────────

    public function test_create_task_persists_record(): void
    {
        Event::fake();

        $task = $this->service->createTask([
            'name' => 'my-task',
            'description' => 'Do it',
        ]);

        $this->assertDatabaseHas(Task::class, ['name' => 'my-task', 'description' => 'Do it']);
        $this->assertNotNull($task->id);
    }

    public function test_create_task_chowns_the_volume_to_the_worker_uid(): void
    {
        Event::fake();

        $task = $this->service->createTask(['name' => 'owned-task', 'description' => 'x']);

        Process::assertRan(function ($process) use ($task): bool {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'chown -R 1000:1000 /workspace')
                && str_contains($cmd, $task->volumeName());
        });
    }

    public function test_create_task_fires_task_created_event(): void
    {
        Event::fake();

        $task = $this->service->createTask(['name' => 'evt-task', 'description' => 'Test']);

        Event::assertDispatched(TaskCreated::class, fn ($e) => $e->task->id === $task->id);
    }

    public function test_create_task_without_auto_concept_does_not_dispatch_job(): void
    {
        Event::fake();

        $this->service->createTask([
            'name' => 'no-concept',
            'description' => 'Test',
            'auto_concept' => false,
        ]);

        Bus::assertNothingDispatched();
        Event::assertNotDispatched(PhaseStarted::class);
    }

    public function test_create_task_with_auto_concept_dispatches_job_and_fires_event(): void
    {
        Event::fake();

        $task = $this->service->createTask([
            'name' => 'auto-concept-task',
            'description' => 'Test',
            'auto_concept' => true,
        ]);

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept' && $j->taskId === $task->id);
        Event::assertDispatched(PhaseStarted::class, fn ($e) => $e->task->id === $task->id && $e->phase === Phase::Concept);
    }

    // ── deleteTask ────────────────────────────────────────────────────────────

    public function test_delete_task_removes_record(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->deleteTask($task);

        $this->assertDatabaseMissing(Task::class, ['id' => $task->id]);
    }

    public function test_delete_task_fires_task_deleted_event(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->deleteTask($task);

        Event::assertDispatched(TaskDeleted::class, fn ($e) => $e->task->id === $task->id);
    }

    // ── startPhase ───────────────────────────────────────────────────────────

    public function test_start_phase_dispatches_job_and_fires_event(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->startPhase($task, Phase::Concept);

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept' && $j->taskId === $task->id);
        Event::assertDispatched(PhaseStarted::class, fn ($e) => $e->task->id === $task->id && $e->phase === Phase::Concept);
    }

    public function test_start_phase_implement_sets_workflow_status(): void
    {
        Event::fake();

        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);
        $this->service->startPhase($task, Phase::Implement);

        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_start_phase_concept_sets_workflow_status_to_concept_running(): void
    {
        Event::fake();

        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);
        $this->service->startPhase($task, Phase::Concept);

        $this->assertSame(WorkflowStatus::ConceptRunning, $task->fresh()->workflow_status);
    }

    public function test_start_phase_concept_from_failed_sets_concept_running(): void
    {
        Event::fake();

        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::Failed]);
        $this->service->startPhase($task, Phase::Concept);

        $this->assertSame(WorkflowStatus::ConceptRunning, $task->fresh()->workflow_status);
    }

    public function test_start_phase_push_from_failed_sets_implement_running(): void
    {
        Event::fake();

        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::Failed]);
        $this->service->startPhase($task, Phase::Push);

        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_start_phase_passes_flags_to_job(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->startPhase($task, Phase::Implement, ['force_unlock' => true]);

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->flags === ['force_unlock' => true]);
    }

    public function test_start_phase_throws_when_phase_already_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        $this->expectException(\RuntimeException::class);
        $this->service->startPhase($task, Phase::Concept);
    }

    public function test_start_phase_does_not_dispatch_job_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        try {
            $this->service->startPhase($task, Phase::Concept);
        } catch (\RuntimeException) {
        }

        Bus::assertNothingDispatched();
    }

    // ── continueImplement ─────────────────────────────────────────────────────

    public function test_continue_implement_dispatches_job_with_continue_flags(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->continueImplement($task, 300);

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement'
            && $j->flags === ['continue' => true, 'max_turns' => 300]);
    }

    public function test_continue_implement_fires_phase_started(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->continueImplement($task, 200);

        Event::assertDispatched(PhaseStarted::class, fn ($e) => $e->phase === Phase::Implement);
    }

    public function test_continue_implement_throws_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'implement']);

        $this->expectException(\RuntimeException::class);
        $this->service->continueImplement($task, 200);
    }

    // ── continueConcept ───────────────────────────────────────────────────────

    public function test_continue_concept_dispatches_job_with_continue_flags(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->continueConcept($task, 45);

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept'
            && $j->flags === ['continue' => true, 'max_turns' => 45]);
    }

    public function test_continue_concept_fires_phase_started(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->continueConcept($task, 30);

        Event::assertDispatched(PhaseStarted::class, fn ($e) => $e->phase === Phase::Concept);
    }

    public function test_continue_concept_throws_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        $this->expectException(\RuntimeException::class);
        $this->service->continueConcept($task, 30);
    }

    // ── forceUnlockImplement ──────────────────────────────────────────────────

    public function test_force_unlock_dispatches_job_with_force_unlock_flag(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->forceUnlockImplement($task);

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement'
            && ($j->flags['force_unlock'] ?? false) === true);
    }

    public function test_force_unlock_throws_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'implement']);

        $this->expectException(\RuntimeException::class);
        $this->service->forceUnlockImplement($task);
    }

    // ── markCompleted ─────────────────────────────────────────────────────────

    public function test_mark_completed_sets_workflow_status(): void
    {
        Event::fake();

        $task = Task::factory()->inReview()->create();
        $this->service->markCompleted($task);

        $this->assertSame(WorkflowStatus::Completed, $task->fresh()->workflow_status);
    }

    public function test_mark_completed_fires_task_completed_event(): void
    {
        Event::fake();

        $task = Task::factory()->inReview()->create();
        $this->service->markCompleted($task);

        Event::assertDispatched(TaskCompleted::class, fn ($e) => $e->task->id === $task->id);
    }

    // ── abortTask ─────────────────────────────────────────────────────────────

    public function test_abort_task_sets_aborted_status(): void
    {
        $this->mock(RunResourceReaper::class)->shouldReceive('reapTask')->once();
        $service = app(TaskService::class);

        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Running,
        ]);
        $service->abortTask($task);

        $this->assertSame(WorkflowStatus::Aborted, $task->fresh()->workflow_status);
        $this->assertSame(PhaseStatus::Failed, $task->fresh()->current_status);
    }

    public function test_abort_task_reaps_the_run_containers(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Running,
        ]);

        $this->mock(RunResourceReaper::class)
            ->shouldReceive('reapTask')->once()
            ->with($task->id);
        $service = app(TaskService::class);

        $service->abortTask($task);
    }

    public function test_abort_task_closes_the_running_phase_run(): void
    {
        $this->mock(RunResourceReaper::class)->shouldReceive('reapTask');
        $service = app(TaskService::class);

        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Running,
        ]);
        $run = PhaseRun::factory()->running()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
        ]);

        $service->abortTask($task);

        $this->assertSame(PhaseStatus::Failed, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    // ── saveConceptNotes ──────────────────────────────────────────────────────

    public function test_save_concept_notes_persists_notes(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->saveConceptNotes($task, 'Some notes');

        $this->assertSame('Some notes', $task->fresh()->concept_notes);
    }

    public function test_save_concept_notes_empty_string_sets_null(): void
    {
        Event::fake();

        $task = Task::factory()->create(['concept_notes' => 'old']);
        $this->service->saveConceptNotes($task, '');

        $this->assertNull($task->fresh()->concept_notes);
    }

    public function test_save_concept_notes_fires_event(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->saveConceptNotes($task, 'Notes');

        Event::assertDispatched(ConceptNotesUpdated::class, fn ($e) => $e->task->id === $task->id);
    }

    // ── saveConceptNotesAndRevise ─────────────────────────────────────────────

    public function test_save_concept_notes_and_revise_persists_and_dispatches(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->saveConceptNotesAndRevise($task, 'Revised notes');

        $this->assertSame('Revised notes', $task->fresh()->concept_notes);
        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'concept');
    }

    public function test_save_concept_notes_and_revise_throws_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'concept']);

        $this->expectException(\RuntimeException::class);
        $this->service->saveConceptNotesAndRevise($task, 'Notes');
    }

    // ── saveImplementNotes ────────────────────────────────────────────────────

    public function test_save_implement_notes_persists_notes(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->saveImplementNotes($task, 'Impl notes');

        $this->assertSame('Impl notes', $task->fresh()->implement_notes);
    }

    public function test_save_implement_notes_fires_event(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->saveImplementNotes($task, 'Notes');

        Event::assertDispatched(ImplementNotesUpdated::class, fn ($e) => $e->task->id === $task->id);
    }

    // ── saveImplementNotesAndRevise ───────────────────────────────────────────

    public function test_save_implement_notes_and_revise_dispatches_implement_job(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->saveImplementNotesAndRevise($task, 'Impl notes');

        // Default (retry): clean reset — no refine flag.
        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement' && $j->flags === []);
    }

    public function test_save_implement_notes_and_revise_with_refine_passes_refine_flag(): void
    {
        Event::fake();

        $task = Task::factory()->create();
        $this->service->saveImplementNotesAndRevise($task, 'Impl notes', refine: true);

        // Refine: re-run on top of the existing working tree, no base reset.
        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement' && $j->flags === ['refine' => true]);
    }

    public function test_save_implement_notes_and_revise_throws_when_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'implement']);

        $this->expectException(\RuntimeException::class);
        $this->service->saveImplementNotesAndRevise($task, 'Notes');
    }

    // ── submitFeedback ────────────────────────────────────────────────────────

    public function test_submit_feedback_dispatches_respond_job(): void
    {
        Event::fake();
        $this->mock(PhaseRunner::class)->shouldReceive('writeFeedbackToVolume')->once();
        $service = app(TaskService::class);

        $task = Task::factory()->inReview()->create();
        $service->submitFeedback($task, 'Please fix this.');

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'respond');
    }

    public function test_submit_feedback_fires_feedback_submitted_event(): void
    {
        Event::fake();
        $this->mock(PhaseRunner::class)->shouldReceive('writeFeedbackToVolume')->once();
        $service = app(TaskService::class);

        $task = Task::factory()->inReview()->create();
        $service->submitFeedback($task, 'Fix it.');

        Event::assertDispatched(FeedbackSubmitted::class, fn ($e) => $e->task->id === $task->id);
    }

    public function test_submit_feedback_throws_when_running(): void
    {
        $this->mock(PhaseRunner::class)->shouldReceive('writeFeedbackToVolume');
        $service = app(TaskService::class);

        $task = Task::factory()->inReview()->create();
        PhaseRun::factory()->running()->create(['task_id' => $task->id, 'phase' => 'respond']);

        $this->expectException(\RuntimeException::class);
        $service->submitFeedback($task, 'Fix it.');
    }

    // ── completePhase ─────────────────────────────────────────────────────────

    public function test_complete_phase_advances_workflow_status(): void
    {
        Event::fake();

        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);
        $this->service->completePhase($task, 'concept', PhaseStatus::Completed);

        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_fires_phase_completed_event(): void
    {
        Event::fake();

        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);
        $this->service->completePhase($task, 'concept', PhaseStatus::Completed);

        Event::assertDispatched(PhaseCompleted::class, fn ($e) => $e->task->id === $task->id
            && $e->phase === Phase::Concept
            && $e->status === PhaseStatus::Completed);
    }

    public function test_complete_phase_failed_fires_event_with_failed_status(): void
    {
        Event::fake();

        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);
        $this->service->completePhase($task, 'concept', PhaseStatus::Failed);

        Event::assertDispatched(PhaseCompleted::class, fn ($e) => $e->status === PhaseStatus::Failed);
    }

    // ── find (used by MCP via InteractsWithTasks) ──────────────────────────────

    public function test_find_by_name(): void
    {
        $task = Task::factory()->create(['name' => 'search-me']);

        $found = $this->service->find('search-me');

        $this->assertNotNull($found);
        $this->assertSame($task->id, $found->id);
    }

    public function test_find_by_id(): void
    {
        $task = Task::factory()->create();

        $found = $this->service->find($task->id);

        $this->assertNotNull($found);
        $this->assertSame($task->id, $found->id);
    }

    public function test_find_returns_null_for_unknown(): void
    {
        $this->assertNull($this->service->find('nonexistent'));
    }
}

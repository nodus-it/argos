<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PhaseRunner;
use App\Services\StateReader;
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
            $mock->shouldReceive('readImplementHistory')->andReturn([]);
            $mock->shouldReceive('readImplementNotesHistory')->andReturn([]);
            $mock->shouldReceive('listLogIterations')->andReturn([]);
        });

        // PhaseRunner uses Symfony\Process directly (docker run …), which
        // Laravel's Process::fake() does not intercept. On hosts without a
        // docker socket — e.g. the worker container that runs phpunit during
        // quality gates — the real exec fails. Stub the methods that the
        // pages call so the tests check Filament behaviour, not Process I/O.
        $this->mock(PhaseRunner::class, function ($mock) {
            $mock->shouldReceive('writeFeedbackToVolume');
            $mock->shouldIgnoreMissing();
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

    public function test_view_task_implement_action_sets_workflow_status_to_implement_running(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'concept', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('implement')
            ->assertNotified();

        $this->assertEquals(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
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

    public function test_mark_completed_visible_only_when_not_completed(): void
    {
        $incomplete = Task::factory()->inReview()->create();
        $complete = Task::factory()->completed()->create();

        Livewire::test(ViewTask::class, ['record' => $incomplete->getKey()])
            ->assertActionVisible('markCompleted');

        Livewire::test(ViewTask::class, ['record' => $complete->getKey()])
            ->assertActionHidden('markCompleted');
    }

    public function test_continue_action_visible_only_when_implement_paused(): void
    {
        $running = Task::factory()->create();
        PhaseRun::factory()->paused()->create(['task_id' => $running->id, 'phase' => 'implement']);

        $other = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $other->id, 'phase' => 'implement', 'status' => 'completed']);

        Livewire::test(ViewTask::class, ['record' => $running->getKey()])
            ->assertActionVisible('continueImplement');

        Livewire::test(ViewTask::class, ['record' => $other->getKey()])
            ->assertActionHidden('continueImplement');
    }

    public function test_continue_action_dispatches_job_with_continue_and_max_turns(): void
    {
        $task = Task::factory()->create(['max_turns' => 250]);
        PhaseRun::factory()->paused()->create(['task_id' => $task->id, 'phase' => 'implement']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('continueImplement', ['max_turns' => 300])
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'implement'
            && $j->flags === ['continue' => true, 'max_turns' => 300]);
    }

    public function test_paused_banner_renders_for_paused_implement_run(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->paused()->create(['task_id' => $task->id, 'phase' => 'implement']);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Implementation paused')
            ->assertSee('turn limit');
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
            ->assertSee('Review Feedback');
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

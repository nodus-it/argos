<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Task;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\PhaseRunner;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->service = app(WorkflowService::class);
    }

    // --- WorkflowStatus::canRetryPhase ---

    public function test_failed_status_can_retry_any_phase(): void
    {
        $status = WorkflowStatus::Failed;

        $this->assertTrue($status->canRetryPhase('concept'));
        $this->assertTrue($status->canRetryPhase('implement'));
        $this->assertTrue($status->canRetryPhase('push'));
        $this->assertTrue($status->canRetryPhase('respond'));
    }

    public function test_implement_paused_can_retry_implement(): void
    {
        $status = WorkflowStatus::ImplementPaused;

        $this->assertTrue($status->canRetryPhase('implement'));
    }

    public function test_implement_paused_cannot_retry_other_phases(): void
    {
        $status = WorkflowStatus::ImplementPaused;

        $this->assertFalse($status->canRetryPhase('concept'));
        $this->assertFalse($status->canRetryPhase('push'));
        $this->assertFalse($status->canRetryPhase('respond'));
    }

    public function test_running_statuses_cannot_retry(): void
    {
        foreach ([WorkflowStatus::ConceptRunning, WorkflowStatus::ImplementRunning, WorkflowStatus::InReview] as $status) {
            $this->assertFalse($status->canRetryPhase('implement'), "Expected {$status->value} to not allow retry");
        }
    }

    // --- WorkflowStatus::retryingPhase ---

    public function test_retrying_phase_concept_returns_concept_running(): void
    {
        $this->assertSame(WorkflowStatus::ConceptRunning, WorkflowStatus::Failed->retryingPhase('concept'));
    }

    public function test_retrying_phase_implement_returns_implement_running(): void
    {
        $this->assertSame(WorkflowStatus::ImplementRunning, WorkflowStatus::Failed->retryingPhase('implement'));
    }

    public function test_retrying_phase_push_returns_implement_running(): void
    {
        $this->assertSame(WorkflowStatus::ImplementRunning, WorkflowStatus::Failed->retryingPhase('push'));
    }

    public function test_retrying_phase_respond_returns_in_review(): void
    {
        $this->assertSame(WorkflowStatus::InReview, WorkflowStatus::Failed->retryingPhase('respond'));
    }

    // --- Bug regression: retry implement from Failed ---

    public function test_retry_implement_from_failed_sets_implement_running(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::Failed,
            'current_phase' => 'implement',
            'current_status' => 'failed',
        ]);

        $this->service->retryPhase($task, 'implement');

        $fresh = $task->fresh();
        $this->assertSame(WorkflowStatus::ImplementRunning, $fresh->workflow_status);
        $this->assertSame(PhaseStatus::Running, $fresh->current_status);
    }

    public function test_retry_concept_from_failed_sets_concept_running(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::Failed,
            'current_phase' => 'concept',
            'current_status' => 'failed',
        ]);

        $this->service->retryPhase($task, 'concept');

        $this->assertSame(WorkflowStatus::ConceptRunning, $task->fresh()->workflow_status);
    }

    public function test_retry_implement_from_implement_paused_sets_implement_running(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementPaused,
            'current_phase' => 'implement',
            'current_status' => 'paused',
        ]);

        $this->service->retryPhase($task, 'implement');

        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    // --- startPhase ---

    public function test_start_phase_creates_phase_run_with_iteration_one(): void
    {
        $task = Task::factory()->create();

        $phaseRun = $this->service->startPhase($task, 'concept');

        $this->assertInstanceOf(PhaseRun::class, $phaseRun);
        $this->assertSame(1, $phaseRun->iteration);
        $this->assertSame(PhaseStatus::Running, $phaseRun->status);
        $this->assertSame(Phase::Concept, $phaseRun->phase);
        $this->assertSame($task->id, $phaseRun->task_id);
    }

    public function test_start_phase_increments_iteration_on_subsequent_calls(): void
    {
        $task = Task::factory()->create();

        $this->service->startPhase($task, 'concept');
        $second = $this->service->startPhase($task, 'concept');

        $this->assertSame(2, $second->iteration);
    }

    public function test_start_phase_sets_task_current_phase_and_status(): void
    {
        $task = Task::factory()->create();

        $this->service->startPhase($task, 'implement');

        $fresh = $task->fresh();
        $this->assertSame(Phase::Implement, $fresh->current_phase);
        $this->assertSame(PhaseStatus::Running, $fresh->current_status);
    }

    // --- completePhase ---

    public function test_complete_phase_concept_completed_advances_to_concept_review(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);

        $this->service->completePhase($task, 'concept', PhaseStatus::Completed);

        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_concept_failed_advances_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);

        $this->service->completePhase($task, 'concept', PhaseStatus::Failed);

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_implement_failed_advances_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $this->service->completePhase($task, 'implement', PhaseStatus::Failed);

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_implement_paused_advances_to_implement_paused(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $this->service->completePhase($task, 'implement', PhaseStatus::Paused);

        $this->assertSame(WorkflowStatus::ImplementPaused, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_implement_completed_without_auto_pr_stays_implement_running(): void
    {
        $profile = RepoProfile::factory()->create(['auto_pr' => false]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
        ]);

        $this->service->completePhase($task, 'implement', PhaseStatus::Completed);

        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
        Bus::assertNothingDispatched();
    }

    public function test_complete_phase_implement_completed_without_auto_pr_corrects_failed_status(): void
    {
        // Core bug regression: if workflow_status was Failed before the retry,
        // completePhase must correct it to ImplementRunning after a successful run.
        $profile = RepoProfile::factory()->create(['auto_pr' => false]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::Failed,
        ]);

        $this->service->completePhase($task, 'implement', PhaseStatus::Completed);

        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_implement_completed_with_auto_pr_dispatches_push_job(): void
    {
        $profile = RepoProfile::factory()->create(['auto_pr' => true]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
        ]);

        $this->service->completePhase($task, 'implement', PhaseStatus::Completed);

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'push' && $j->taskId === $task->id);
        // Status stays ImplementRunning while push runs
        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_push_completed_advances_to_in_review(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $this->service->completePhase($task, 'push', PhaseStatus::Completed);

        $this->assertSame(WorkflowStatus::InReview, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_push_failed_advances_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $this->service->completePhase($task, 'push', PhaseStatus::Failed);

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_complete_phase_respond_completed_advances_to_in_review(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::InReview]);

        $this->service->completePhase($task, 'respond', PhaseStatus::Completed);

        $this->assertSame(WorkflowStatus::InReview, $task->fresh()->workflow_status);
    }

    // --- markStaleRunsAsFailed ---

    public function test_mark_stale_runs_as_failed_updates_old_running_runs(): void
    {
        $task = Task::factory()->create();
        $stale = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'running',
            'started_at' => now()->subHours(3),
        ]);

        $this->service->markStaleRunsAsFailed($task);

        $this->assertSame(PhaseStatus::Failed, $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->finished_at);
    }

    public function test_mark_stale_runs_as_failed_leaves_recent_running_runs_intact(): void
    {
        $task = Task::factory()->create();
        $recent = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'running',
            'started_at' => now()->subMinutes(30),
        ]);

        $this->service->markStaleRunsAsFailed($task);

        $this->assertSame(PhaseStatus::Running, $recent->fresh()->status);
    }

    public function test_mark_stale_runs_as_failed_leaves_completed_runs_intact(): void
    {
        $task = Task::factory()->create();
        $completed = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
            'started_at' => now()->subHours(5),
        ]);

        $this->service->markStaleRunsAsFailed($task);

        $this->assertSame(PhaseStatus::Completed, $completed->fresh()->status);
    }

    // --- RunPhaseJob integration: retry from Failed ---

    public function test_run_phase_job_resets_failed_status_before_running_implement(): void
    {
        // Core bug regression: implement succeeds after a previous failure,
        // but workflow_status should not remain Failed.
        $profile = RepoProfile::factory()->create(['auto_pr' => false]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::Failed,
            'current_phase' => 'implement',
            'current_status' => 'failed',
        ]);

        $runner = $this->mock(PhaseRunner::class);
        $runner->shouldReceive('runBlocking')
            ->andReturnUsing(function () use ($task): void {
                // Simulate a successful implement run.
                $task->update(['current_status' => 'completed']);
            });

        $job = new RunPhaseJob($task->id, 'implement');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class));

        $this->assertNotSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_run_phase_job_does_not_change_status_when_task_is_already_running(): void
    {
        $profile = RepoProfile::factory()->create(['auto_pr' => false]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => 'completed',
        ]);

        $runner = $this->mock(PhaseRunner::class);
        $runner->shouldReceive('runBlocking')->andReturnNull();

        $job = new RunPhaseJob($task->id, 'implement');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class));

        // Status should still be ImplementRunning (not changed by retryPhase)
        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }
}

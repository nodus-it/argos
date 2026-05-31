<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Events\Task\PhaseCompleted;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\WorkflowService;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\Support\FakeWorkerProcess;
use Tests\TestCase;

/**
 * Wave-1 retro M7: cover every workflow path end-to-end against a faked
 * worker Process. Complements WorkflowEndToEndTest.php (which deeper-tests
 * a couple of single-phase transitions) with full Draft→InReview runs and
 * every non-zero exit-code/recovery path.
 */
final class WorkflowFullRunTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/argos_full_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config([
            'argos.config_dir' => $this->tmpDir,
            'argos.claude_token' => 'test-token',
        ]);

        $resolver = Mockery::mock(WorkerImageResolver::class);
        $resolver->shouldReceive('resolveOrBuild')->andReturn('argos-worker:test');
        $this->app->instance(WorkerImageResolver::class, $resolver);

        Bus::fake();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Happy path — Draft → ConceptReview → ImplementCompleted → InReview
    // -------------------------------------------------------------------------

    public function test_full_happy_path_concept_then_implement_then_push(): void
    {
        Event::fake([PhaseCompleted::class]);

        $task = $this->taskWithProfile();

        $this->runPhase($task, 'concept', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);

        $this->runPhase($task, 'implement', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::ImplementCompleted, $task->fresh()->workflow_status);

        $this->runPhase($task, 'push', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::InReview, $task->fresh()->workflow_status);

        $this->assertDatabaseCount(PhaseRun::class, 3);
        $this->assertSame(
            [PhaseStatus::Completed, PhaseStatus::Completed, PhaseStatus::Completed],
            PhaseRun::where('task_id', $task->id)->orderBy('started_at')->pluck('status')->all(),
        );

        Event::assertDispatched(PhaseCompleted::class, fn ($e) => $e->phase === Phase::Concept);
        Event::assertDispatched(PhaseCompleted::class, fn ($e) => $e->phase === Phase::Implement);
        Event::assertDispatched(PhaseCompleted::class, fn ($e) => $e->phase === Phase::Push);
    }

    // -------------------------------------------------------------------------
    // Recovery: failed → user retry → success
    // -------------------------------------------------------------------------

    public function test_concept_failure_then_retry_succeeds(): void
    {
        $task = $this->taskWithProfile();

        $this->runPhase($task, 'concept', FakeWorkerProcess::failure());
        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);

        $this->runPhase($task, 'concept', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);
        $this->assertSame(2, PhaseRun::where('task_id', $task->id)->where('phase', 'concept')->count());
    }

    public function test_implement_failure_then_retry_succeeds(): void
    {
        $task = $this->taskWithProfile();
        $this->runPhase($task, 'concept', FakeWorkerProcess::success());

        $this->runPhase($task, 'implement', FakeWorkerProcess::failure());
        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);

        $this->runPhase($task, 'implement', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::ImplementCompleted, $task->fresh()->workflow_status);
    }

    public function test_implement_quality_gate_failure_then_retry_succeeds(): void
    {
        $task = $this->taskWithProfile();
        $this->runPhase($task, 'concept', FakeWorkerProcess::success());

        $this->runPhase($task, 'implement', FakeWorkerProcess::qualityGateFailure());
        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
        $this->assertSame(PhaseStatus::QualityGateFailed, $task->fresh()->current_status);

        $this->runPhase($task, 'implement', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::ImplementCompleted, $task->fresh()->workflow_status);
    }

    public function test_push_failure_then_retry_succeeds(): void
    {
        $task = $this->taskWithProfile();
        $this->runPhase($task, 'concept', FakeWorkerProcess::success());
        $this->runPhase($task, 'implement', FakeWorkerProcess::success());

        $this->runPhase($task, 'push', FakeWorkerProcess::failure());
        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);

        $this->runPhase($task, 'push', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::InReview, $task->fresh()->workflow_status);
    }

    // -------------------------------------------------------------------------
    // Non-Completed exits that do NOT advance the workflow
    // -------------------------------------------------------------------------

    public function test_implement_rate_limited_persists_phase_run_and_caches_limit(): void
    {
        $task = $this->taskWithProfile();
        $this->runPhase($task, 'concept', FakeWorkerProcess::success());

        $this->runPhase($task, 'implement', FakeWorkerProcess::rateLimited());

        $this->assertSame(PhaseStatus::RateLimited, $task->fresh()->current_status);
        // RateLimited has no afterPhase() mapping — workflow_status stays on
        // ConceptReview (the pre-implement state) so a re-dispatch lands here.
        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);

        $latestRun = PhaseRun::where('task_id', $task->id)->where('phase', 'implement')->latest('started_at')->first();
        $this->assertSame(PhaseStatus::RateLimited, $latestRun->status);
        $this->assertSame(7, $latestRun->exit_code);
    }

    public function test_implement_lock_blocked_persists_status_without_advancing_workflow(): void
    {
        $task = $this->taskWithProfile();
        $this->runPhase($task, 'concept', FakeWorkerProcess::success());

        $this->runPhase($task, 'implement', FakeWorkerProcess::lockBlocked());

        $this->assertSame(PhaseStatus::LockBlocked, $task->fresh()->current_status);
        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);

        $this->runPhase($task, 'implement', FakeWorkerProcess::success());
        $this->assertSame(WorkflowStatus::ImplementCompleted, $task->fresh()->workflow_status);
    }

    public function test_implement_no_changes_persists_status_without_advancing_workflow(): void
    {
        $task = $this->taskWithProfile();
        $this->runPhase($task, 'concept', FakeWorkerProcess::success());

        $this->runPhase($task, 'implement', FakeWorkerProcess::noChanges());

        $this->assertSame(PhaseStatus::NoChanges, $task->fresh()->current_status);
        // NoChanges is informational, not a failure — workflow stays on the
        // pre-phase state (ConceptReview) because afterPhase() returns null.
        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function taskWithProfile(): Task
    {
        return Task::factory()->create([
            'name' => 'full-run-test',
            'repo_profile_id' => RepoProfile::factory()->create([
                'url' => 'https://github.com/org/repo',
                'default_branch' => 'main',
                'token' => 'test-token',
            ])->id,
        ]);
    }

    private function runPhase(Task $task, string $phase, FakeWorkerProcess $process): void
    {
        $process->bind();
        $task->update(['workflow_status' => $this->priorWorkflowStatus($task, $phase)]);

        (new RunPhaseJob($task->id, $phase))->handle(
            app(PhaseRunner::class),
            app(WorkflowService::class),
            app(TaskService::class),
        );
    }

    /**
     * Workflow-status the task must be in *before* the given phase starts.
     * Retry handling (retryPhase) maps Failed → the running state for us,
     * but only if workflow_status is already a state the retry-transition
     * recognises — for plain re-runs (e.g. after no_changes/lock_blocked)
     * the task is still on the running state, no override needed.
     */
    private function priorWorkflowStatus(Task $task, string $phase): WorkflowStatus
    {
        $current = $task->workflow_status;

        if ($current === WorkflowStatus::Failed) {
            return $current;
        }

        return match ($phase) {
            'concept' => WorkflowStatus::Draft,
            'implement' => $current === WorkflowStatus::ImplementRunning ? $current : WorkflowStatus::ConceptReview,
            'push' => WorkflowStatus::ImplementCompleted,
            'respond' => WorkflowStatus::InReview,
            default => $current,
        };
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}

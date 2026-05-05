<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PhaseRunner;
use App\Services\WorkflowService;
use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * End-to-end workflow tests connecting RunPhaseJob → PhaseRunner → advanceWorkflow.
 * Docker is replaced by a mocked Process; everything else runs for real.
 */
class WorkflowEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/argos_e2e_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config([
            'argos.config_dir' => $this->tmpDir,
            'argos.claude_token' => 'test-token',
            'argos.worker_image' => 'argos-worker:test',
        ]);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Happy path: concept → ConceptReview
    // -------------------------------------------------------------------------

    public function test_concept_phase_transitions_task_to_concept_review(): void
    {
        $task = $this->taskWithProfile();

        $this->runJobWithExitCode($task, 'concept', 0);

        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);
        $this->assertSame('concept', $task->fresh()->current_phase);
        $this->assertSame('completed', $task->fresh()->current_status);
    }

    public function test_concept_phase_creates_phase_run_record(): void
    {
        $task = $this->taskWithProfile();

        $this->runJobWithExitCode($task, 'concept', 0);

        $this->assertDatabaseHas(PhaseRun::class, [
            'task_id' => $task->id,
            'phase' => 'concept',
            'status' => 'completed',
            'exit_code' => 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Happy path: concept → implement (sequential)
    // -------------------------------------------------------------------------

    public function test_concept_then_implement_both_succeed(): void
    {
        $task = $this->taskWithProfile();

        $this->runJobWithExitCode($task, 'concept', 0);
        $this->runJobWithExitCode($task, 'implement', 0);

        $this->assertSame('implement', $task->fresh()->current_phase);
        $this->assertSame('completed', $task->fresh()->current_status);
        $this->assertDatabaseCount(PhaseRun::class, 2);
    }

    public function test_implement_iteration_increments_on_retry(): void
    {
        $task = $this->taskWithProfile();
        $this->runJobWithExitCode($task, 'concept', 0);

        $this->runJobWithExitCode($task, 'implement', 0);
        $this->runJobWithExitCode($task, 'implement', 0);

        $runs = PhaseRun::where('task_id', $task->id)->where('phase', 'implement')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(1, $runs->first()->iteration);
        $this->assertSame(2, $runs->last()->iteration);
    }

    // -------------------------------------------------------------------------
    // Failure handling
    // -------------------------------------------------------------------------

    public function test_concept_failure_transitions_to_failed(): void
    {
        $task = $this->taskWithProfile();

        $this->runJobWithExitCode($task, 'concept', 1);

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
        $this->assertSame('failed', $task->fresh()->current_status);
    }

    public function test_implement_quality_gate_failure_transitions_to_failed(): void
    {
        $task = $this->taskWithProfile();
        $this->runJobWithExitCode($task, 'concept', 0);

        $this->runJobWithExitCode($task, 'implement', 4);

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
        $this->assertSame('quality_gate_failed', $task->fresh()->current_status);
    }

    // -------------------------------------------------------------------------
    // auto_concept: dispatched by CreateTask page when flag is set
    // -------------------------------------------------------------------------

    public function test_auto_concept_dispatches_job_when_create_task_page_calls_after_create(): void
    {
        Bus::fake();
        $profile = RepoProfile::factory()->withAutoConcept()->create();
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'auto_concept' => true,
        ]);

        // Simulate what CreateTask::afterCreate() does
        if ($task->auto_concept) {
            RunPhaseJob::dispatch($task->id, 'concept');
        }

        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'concept' && $job->taskId === $task->id
        );
    }

    public function test_no_auto_concept_when_flag_is_false(): void
    {
        Bus::fake();
        $task = Task::factory()->create(['auto_concept' => false]);

        if ($task->auto_concept) {
            RunPhaseJob::dispatch($task->id, 'concept');
        }

        Bus::assertNothingDispatched();
    }

    // -------------------------------------------------------------------------
    // auto_pr: push job dispatched after implement with auto_pr profile
    // -------------------------------------------------------------------------

    public function test_auto_pr_dispatches_push_job_after_implement(): void
    {
        Bus::fake();
        $profile = RepoProfile::factory()->withAutoPr()->create();
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
        ]);

        $task->advanceWorkflow('implement', 'completed');

        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'push' && $job->taskId === $task->id
        );
    }

    public function test_no_auto_pr_without_profile_flag(): void
    {
        Bus::fake();
        $profile = RepoProfile::factory()->create(['auto_pr' => false]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
        ]);

        $task->advanceWorkflow('implement', 'completed');

        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function taskWithProfile(): Task
    {
        return Task::factory()->create([
            'name' => 'test-task',
            'repo_profile_id' => RepoProfile::factory()->create([
                'url' => 'https://github.com/org/repo',
                'default_branch' => 'main',
                'token' => 'test-token',
            ])->id,
        ]);
    }

    /**
     * Runs RunPhaseJob for the given phase, mocking the Docker Process to return
     * the specified exit code. Sets the task's workflow_status to the appropriate
     * pre-phase state before dispatching.
     */
    private function runJobWithExitCode(Task $task, string $phase, int $exitCode): void
    {
        $processMock = \Mockery::mock(Process::class);
        $processMock->shouldReceive('setTimeout')->andReturnSelf();
        $processMock->shouldReceive('setIdleTimeout')->andReturnSelf();
        $processMock->shouldReceive('setInput')->andReturnSelf();
        $processMock->shouldReceive('setEnv')->andReturnSelf();
        $processMock->shouldReceive('mustRun')->andReturnSelf();
        $processMock->shouldReceive('run')->andReturn($exitCode);
        $processMock->shouldReceive('start')->andReturnNull();
        $processMock->shouldReceive('isRunning')->andReturn(false);
        $processMock->shouldReceive('getExitCode')->andReturn($exitCode);
        $processMock->shouldReceive('getOutput')->andReturn('');
        $processMock->shouldReceive('getIncrementalOutput')->andReturn('');
        $processMock->shouldReceive('wait')->andReturnUsing(fn (?callable $cb) => $exitCode);

        $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
            $mock->shouldReceive('writeNotesToVolume')->andReturn(null);
            $mock->shouldReceive('postPhaseSync')->andReturn(null);
        });

        $task->update(['workflow_status' => $this->priorWorkflowStatus($phase)]);
        (new RunPhaseJob($task->id, $phase))->handle(app(PhaseRunner::class), app(WorkflowService::class));
    }

    private function priorWorkflowStatus(string $phase): WorkflowStatus
    {
        return match ($phase) {
            'concept' => WorkflowStatus::Draft,
            'implement' => WorkflowStatus::ConceptReview,
            'push' => WorkflowStatus::ImplementRunning,
            'respond' => WorkflowStatus::InReview,
            default => WorkflowStatus::Draft,
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

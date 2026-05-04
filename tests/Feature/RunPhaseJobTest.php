<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Phase\PhaseRunner;
use App\Domain\Task\WorkflowService;
use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RunPhaseJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_handle_calls_run_blocking_with_correct_task_and_phase(): void
    {
        $task = Task::factory()->create(['current_status' => 'completed']);

        $runner = $this->mock(PhaseRunner::class);
        $runner->shouldReceive('runBlocking')
            ->once()
            ->with(\Mockery::on(fn ($t) => $t->id === $task->id), 'concept', []);

        $job = new RunPhaseJob($task->id, 'concept');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class));
    }

    public function test_handle_passes_flags_to_run_blocking(): void
    {
        $task = Task::factory()->create(['current_status' => 'completed']);
        $flags = ['skip_tests' => true];

        $runner = $this->mock(PhaseRunner::class);
        $runner->shouldReceive('runBlocking')
            ->once()
            ->with(\Mockery::any(), 'implement', $flags);

        $job = new RunPhaseJob($task->id, 'implement', $flags);
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class));
    }

    public function test_handle_advances_workflow_after_run(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => 'completed',
        ]);

        $this->mock(PhaseRunner::class)->shouldReceive('runBlocking');

        $job = new RunPhaseJob($task->id, 'concept');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class));

        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);
    }

    public function test_handle_uses_failed_status_when_current_status_is_null(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => null,
        ]);

        $this->mock(PhaseRunner::class)->shouldReceive('runBlocking');

        $job = new RunPhaseJob($task->id, 'concept');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class));

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_job_has_one_hour_timeout(): void
    {
        $job = new RunPhaseJob('task-id', 'concept');

        $this->assertSame(3600, $job->timeout);
    }

    public function test_handle_dispatches_push_job_when_implement_completes_with_auto_pr(): void
    {
        $profile = RepoProfile::factory()->create(['auto_pr' => true]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => 'completed',
        ]);

        $this->mock(PhaseRunner::class)->shouldReceive('runBlocking');

        $job = new RunPhaseJob($task->id, 'implement');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class));

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'push');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Phase;
use App\Enums\WorkflowStatus;
use App\Events\Task\PhaseCompleted;
use App\Jobs\RunPhaseJob;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));
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
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));
    }

    public function test_handle_advances_workflow_after_run(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => 'completed',
        ]);

        $this->mock(PhaseRunner::class)->shouldReceive('runBlocking');

        $job = new RunPhaseJob($task->id, 'concept');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));

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
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_job_has_one_hour_timeout(): void
    {
        $job = new RunPhaseJob('task-id', 'concept');

        $this->assertSame(3600, $job->timeout);
    }

    public function test_failed_logs_exhausted_error_entry(): void
    {
        $logged = null;
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            if ($event->level === 'error' && isset($event->context['exhausted'])) {
                $logged = $event->context;
            }
        });

        $exception = new \RuntimeException('timeout kill');
        $job = new RunPhaseJob('task-42', 'implement');
        $job->failed($exception);

        $this->assertNotNull($logged, 'Expected an error log entry with exhausted context');
        $this->assertTrue($logged['exhausted']);
        $this->assertSame('task-42', $logged['task']);
        $this->assertSame('implement', $logged['phase']);
        $this->assertSame('timeout kill', $logged['error']);
        $this->assertSame(\RuntimeException::class, $logged['class']);
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
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'push');
    }

    public function test_handle_fires_phase_completed_event(): void
    {
        Event::fake();

        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => 'completed',
        ]);

        $this->mock(PhaseRunner::class)->shouldReceive('runBlocking');

        $job = new RunPhaseJob($task->id, 'concept');
        $job->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));

        Event::assertDispatched(PhaseCompleted::class, fn ($e) => $e->task->id === $task->id
            && $e->phase === Phase::Concept);
    }
}

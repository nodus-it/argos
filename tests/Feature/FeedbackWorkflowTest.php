<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\WorkflowService;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Tests the feedback/notes mechanism: concept_notes, implement_notes, and the respond cycle.
 *
 * Feedback flows:
 *   concept_notes  → written to volume before concept, saved in PhaseRun, cleared from task
 *   implement_notes → same pattern for implement
 *   respond        → writeFeedbackToVolume called, respond phase dispatched, workflow stays InReview
 */
class FeedbackWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/argos_feedback_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config([
            'argos.config_dir' => $this->tmpDir,
            'argos.claude_token' => 'test-token',
        ]);

        $resolver = Mockery::mock(WorkerImageResolver::class);
        $resolver->shouldReceive('resolveOrBuild')->andReturn('argos-worker:test');
        $this->app->instance(WorkerImageResolver::class, $resolver);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // concept_notes flow
    // -------------------------------------------------------------------------

    public function test_concept_notes_are_passed_to_write_notes_before_phase(): void
    {
        $task = $this->taskWithProfile(['concept_notes' => 'Bitte einfacher halten.']);
        $capturedNotes = null;

        $this->mockRunnerCapturingNotes($task, 'concept', 0, $capturedNotes);

        $this->assertSame('Bitte einfacher halten.', $capturedNotes);
    }

    public function test_concept_notes_cleared_from_task_after_phase(): void
    {
        $task = $this->taskWithProfile(['concept_notes' => 'Anmerkung vor dem Lauf.']);

        $this->runJobWithRealNotes($task, 'concept', 0);

        $this->assertNull($task->fresh()->concept_notes);
    }

    public function test_concept_notes_saved_in_phase_run(): void
    {
        $task = $this->taskWithProfile(['concept_notes' => 'Meine Anmerkung.']);

        $this->runJobWithRealNotes($task, 'concept', 0);

        $this->assertDatabaseHas(PhaseRun::class, [
            'task_id' => $task->id,
            'phase' => 'concept',
            'concept_notes' => 'Meine Anmerkung.',
        ]);
    }

    public function test_concept_without_notes_creates_phase_run_with_null_notes(): void
    {
        $task = $this->taskWithProfile(['concept_notes' => null]);

        $this->runJobWithRealNotes($task, 'concept', 0);

        $this->assertDatabaseHas(PhaseRun::class, [
            'task_id' => $task->id,
            'phase' => 'concept',
            'concept_notes' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // implement_notes flow
    // -------------------------------------------------------------------------

    public function test_implement_notes_are_passed_to_write_notes_before_phase(): void
    {
        $task = $this->taskWithProfile([
            'workflow_status' => WorkflowStatus::ConceptReview,
            'implement_notes' => 'Auch Tests schreiben.',
        ]);
        $capturedNotes = null;

        $this->mockRunnerCapturingNotes($task, 'implement', 0, $capturedNotes);

        $this->assertSame('Auch Tests schreiben.', $capturedNotes);
    }

    public function test_implement_notes_cleared_from_task_after_phase(): void
    {
        $task = $this->taskWithProfile([
            'workflow_status' => WorkflowStatus::ConceptReview,
            'implement_notes' => 'Implementierungs-Anmerkung.',
        ]);

        $this->runJobWithRealNotes($task, 'implement', 0);

        $this->assertNull($task->fresh()->implement_notes);
    }

    public function test_implement_notes_saved_in_phase_run(): void
    {
        $task = $this->taskWithProfile([
            'workflow_status' => WorkflowStatus::ConceptReview,
            'implement_notes' => 'Bitte strict_types beachten.',
        ]);

        $this->runJobWithRealNotes($task, 'implement', 0);

        $this->assertDatabaseHas(PhaseRun::class, [
            'task_id' => $task->id,
            'phase' => 'implement',
            'implement_notes' => 'Bitte strict_types beachten.',
        ]);
    }

    // -------------------------------------------------------------------------
    // respond cycle: feedback → respond phase → stays InReview
    // -------------------------------------------------------------------------

    public function test_respond_phase_keeps_workflow_in_review(): void
    {
        $task = $this->taskWithProfile(['workflow_status' => WorkflowStatus::InReview]);

        $this->runJobWithRealNotes($task, 'respond', 0);

        $this->assertSame(WorkflowStatus::InReview, $task->fresh()->workflow_status);
    }

    public function test_respond_phase_creates_phase_run_record(): void
    {
        $task = $this->taskWithProfile(['workflow_status' => WorkflowStatus::InReview]);

        $this->runJobWithRealNotes($task, 'respond', 0);

        $this->assertDatabaseHas(PhaseRun::class, [
            'task_id' => $task->id,
            'phase' => 'respond',
            'status' => 'completed',
        ]);
    }

    public function test_respond_failure_transitions_to_failed(): void
    {
        $task = $this->taskWithProfile(['workflow_status' => WorkflowStatus::InReview]);

        $this->runJobWithRealNotes($task, 'respond', 1);

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_write_feedback_to_volume_passes_text_via_env(): void
    {
        $task = $this->taskWithProfile();
        $capturedEnv = null;

        $processMock = Mockery::mock(Process::class);
        $processMock->shouldReceive('setEnv')->andReturnUsing(function (array $env) use (&$capturedEnv, $processMock) {
            $capturedEnv = $env;

            return $processMock;
        });
        $processMock->shouldReceive('setTimeout')->andReturnSelf();
        $processMock->shouldReceive('mustRun')->andReturnSelf();

        $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->once()->andReturn($processMock);
        });

        app(PhaseRunner::class)->writeFeedbackToVolume($task, 'Das ist mein Feedback.');

        $this->assertSame('Das ist mein Feedback.', $capturedEnv['FEEDBACK'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function taskWithProfile(array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'name' => 'feedback-test-task',
            'repo_profile_id' => RepoProfile::factory()->create([
                'url' => 'https://github.com/org/repo',
                'default_branch' => 'main',
                'token' => 'test-token',
            ])->id,
        ], $overrides));
    }

    /**
     * Mocks the runner so that writeNotesToVolume captures what notes were passed.
     */
    private function mockRunnerCapturingNotes(Task $task, string $phase, int $exitCode, mixed &$captured): void
    {
        $processMock = $this->makeProcessMock($exitCode);
        $notesMethod = $phase === 'implement' ? 'writeImplementNotesToVolume' : 'writeNotesToVolume';

        $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock, $notesMethod, &$captured): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
            $mock->shouldReceive($notesMethod)
                ->andReturnUsing(function (Task $t) use ($notesMethod, &$captured): ?string {
                    $notes = $notesMethod === 'writeImplementNotesToVolume'
                        ? $t->implement_notes
                        : $t->concept_notes;
                    $captured = $notes;

                    return $notes;
                });
            $mock->shouldReceive('postPhaseSync')->andReturn(null);
        });

        (new RunPhaseJob($task->id, $phase))->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));
    }

    /**
     * Runs the job with the real writeNotesToVolume (mocks only the Docker process),
     * so that postPhaseSync can save and clear notes on the real DB records.
     */
    private function runJobWithRealNotes(Task $task, string $phase, int $exitCode): void
    {
        $processMock = $this->makeProcessMock($exitCode);

        $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
        });

        (new RunPhaseJob($task->id, $phase))->handle(app(PhaseRunner::class), app(WorkflowService::class), app(TaskService::class));
    }

    private function makeProcessMock(int $exitCode): Process
    {
        $mock = Mockery::mock(Process::class);
        $mock->shouldReceive('setTimeout')->andReturnSelf();
        $mock->shouldReceive('setIdleTimeout')->andReturnSelf();
        $mock->shouldReceive('setInput')->andReturnSelf();
        $mock->shouldReceive('setEnv')->andReturnSelf();
        $mock->shouldReceive('mustRun')->andReturnSelf();
        $mock->shouldReceive('run')->andReturn($exitCode);
        $mock->shouldReceive('start')->andReturnNull();
        $mock->shouldReceive('isRunning')->andReturn(false);
        $mock->shouldReceive('isSuccessful')->andReturn($exitCode === 0);
        $mock->shouldReceive('getExitCode')->andReturn($exitCode);
        $mock->shouldReceive('getOutput')->andReturn('');
        $mock->shouldReceive('getIncrementalOutput')->andReturn('');
        $mock->shouldReceive('wait')->andReturnUsing(fn (?callable $cb) => $exitCode);

        return $mock;
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

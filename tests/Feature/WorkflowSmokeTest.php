<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Jobs\RunPhaseJob;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\Task\TaskService;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\WorkflowService;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Support\FakeWorkerProcess;
use Tests\TestCase;

/**
 * Wave-1 retro M10 (reshaped from "smoke as phase step" to "smoke as CI
 * test"): one test that drives a complete workflow run via the M7
 * FakeWorkerProcess infrastructure and asserts the Filament ViewTask page
 * renders the correct workflow-status string after each phase. Catches
 * the "phase done but UI shows wrong badge" class of bugs in CI instead
 * of at PR-merge time.
 */
final class WorkflowSmokeTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/argos_smoke_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config([
            'argos.config_dir' => $this->tmpDir,
            'argos.claude_token' => 'test-token',
        ]);

        $resolver = Mockery::mock(WorkerImageResolver::class);
        $resolver->shouldReceive('resolveOrBuild')->andReturn('argos-worker:test');
        $this->app->instance(WorkerImageResolver::class, $resolver);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    public function test_full_happy_workflow_renders_each_phase_state_in_view_task(): void
    {
        $task = $this->taskWithProfile();

        // 0. Draft — fresh task, nothing run yet
        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Draft');

        // 1. concept success → ConceptReview
        $this->runPhase($task, 'concept', FakeWorkerProcess::success());

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Concept ready');

        // 2. implement success → ImplementCompleted
        $this->runPhase($task, 'implement', FakeWorkerProcess::success());

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Implementation completed');

        // 3. push success → InReview
        $this->runPhase($task, 'push', FakeWorkerProcess::success());

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('In Review');
    }

    public function test_failed_workflow_renders_failed_state_in_view_task(): void
    {
        $task = $this->taskWithProfile();

        $this->runPhase($task, 'concept', FakeWorkerProcess::failure());

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSuccessful()
            ->assertSee('Failed');
    }

    private function taskWithProfile(): Task
    {
        return Task::factory()->create([
            'name' => 'smoke-test',
            'user_id' => $this->user->id,
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

    private function priorWorkflowStatus(Task $task, string $phase): WorkflowStatus
    {
        $current = $task->workflow_status;

        if ($current === WorkflowStatus::Failed) {
            return $current;
        }

        return match ($phase) {
            'concept' => WorkflowStatus::Draft,
            'implement' => WorkflowStatus::ConceptReview,
            'push' => WorkflowStatus::ImplementCompleted,
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

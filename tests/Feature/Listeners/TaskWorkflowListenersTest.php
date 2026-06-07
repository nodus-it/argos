<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Events\Task\PhaseCompleted;
use App\Events\Task\TaskCompleted;
use App\Jobs\RunPhaseJob;
use App\Jobs\StopDemoJob;
use App\Models\Demo;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The follow-up side-effects of completePhase/markCompleted now live in
 * listeners. These tests dispatch the real events so they also cover the
 * Event::listen wiring in AppServiceProvider.
 */
class TaskWorkflowListenersTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_completed_chains_into_push_when_auto_pr(): void
    {
        Bus::fake();
        $profile = RepoProfile::factory()->create(['auto_pr' => true]);
        $task = Task::factory()->for($profile, 'repoProfile')->create();

        Event::dispatch(new PhaseCompleted($task, Phase::Implement, PhaseStatus::Completed));

        Bus::assertDispatched(RunPhaseJob::class, fn ($j) => $j->phase === 'push' && $j->taskId === $task->id);
    }

    public function test_phase_completed_does_not_chain_without_auto_pr(): void
    {
        Bus::fake();
        $profile = RepoProfile::factory()->create(['auto_pr' => false]);
        $task = Task::factory()->for($profile, 'repoProfile')->create();

        Event::dispatch(new PhaseCompleted($task, Phase::Implement, PhaseStatus::Completed));

        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    public function test_phase_completed_does_not_chain_for_non_implement_phase(): void
    {
        Bus::fake();
        $profile = RepoProfile::factory()->create(['auto_pr' => true]);
        $task = Task::factory()->for($profile, 'repoProfile')->create();

        Event::dispatch(new PhaseCompleted($task, Phase::Concept, PhaseStatus::Completed));

        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    public function test_push_completed_stops_a_live_demo(): void
    {
        Bus::fake();
        $task = Task::factory()->create();
        Demo::factory()->live()->create(['task_id' => $task->id]);

        Event::dispatch(new PhaseCompleted($task, Phase::Push, PhaseStatus::Completed));

        Bus::assertDispatched(StopDemoJob::class, fn ($j) => $j->taskId === $task->id);
    }

    public function test_task_completed_removes_the_workspace_volume(): void
    {
        Process::fake();
        $task = Task::factory()->create();

        Event::dispatch(new TaskCompleted($task));

        Process::assertRan(function ($p) use ($task): bool {
            $cmd = is_array($p->command) ? implode(' ', $p->command) : (string) $p->command;

            return str_contains($cmd, 'volume rm') && str_contains($cmd, $task->volumeName());
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\ReapOrphanedRunsJob;
use App\Models\Task;
use App\Services\Workflow\RunResourceReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CleanupOrphanedRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_the_sweep_job(): void
    {
        Bus::fake();

        $this->artisan('argos:cleanup-orphans')->assertSuccessful();

        Bus::assertDispatched(ReapOrphanedRunsJob::class);
    }

    public function test_job_keeps_running_tasks_and_reaps_the_rest(): void
    {
        $running = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Running,
        ]);
        // An idle task — its id must NOT be in the keep set.
        Task::factory()->create([
            'workflow_status' => WorkflowStatus::Completed,
            'current_status' => PhaseStatus::Completed,
        ]);

        $reaper = $this->mock(RunResourceReaper::class);
        $reaper->shouldReceive('reapExcept')
            ->once()
            ->with(\Mockery::on(fn (array $keep): bool => $keep === [$running->id]));

        (new ReapOrphanedRunsJob)->handle($reaper);
    }
}

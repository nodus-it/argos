<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhaseRun;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskCurrentPhaseStartedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_no_phase_runs_exist(): void
    {
        $task = Task::factory()->create();

        $this->assertNull($task->currentPhaseStartedAt());
    }

    public function test_returns_null_when_phase_run_has_no_started_at(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'started_at' => null,
        ]);

        $this->assertNull($task->currentPhaseStartedAt());
    }

    public function test_returns_started_at_of_the_single_running_phase_run(): void
    {
        $task = Task::factory()->create();
        $startedAt = Carbon::parse('2025-01-01 10:00:00');
        PhaseRun::factory()->running()->create([
            'task_id' => $task->id,
            'started_at' => $startedAt,
        ]);

        $result = $task->currentPhaseStartedAt();

        $this->assertNotNull($result);
        $this->assertTrue($startedAt->equalTo($result));
    }

    public function test_returns_null_when_phase_run_is_not_running(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'started_at' => Carbon::parse('2025-01-01 10:00:00'),
        ]);

        $this->assertNull($task->currentPhaseStartedAt());
    }

    public function test_returns_most_recent_started_at_of_running_phase_runs(): void
    {
        $task = Task::factory()->create();
        $older = Carbon::parse('2025-01-01 09:00:00');
        $newer = Carbon::parse('2025-01-01 10:00:00');

        PhaseRun::factory()->running()->create([
            'task_id' => $task->id,
            'started_at' => $older,
        ]);
        PhaseRun::factory()->running()->create([
            'task_id' => $task->id,
            'started_at' => $newer,
        ]);

        $result = $task->currentPhaseStartedAt();

        $this->assertNotNull($result);
        $this->assertTrue($newer->equalTo($result));
    }

    public function test_ignores_phase_runs_from_other_tasks(): void
    {
        $task = Task::factory()->create();
        $otherTask = Task::factory()->create();

        PhaseRun::factory()->create([
            'task_id' => $otherTask->id,
            'started_at' => now(),
        ]);

        $this->assertNull($task->currentPhaseStartedAt());
    }
}

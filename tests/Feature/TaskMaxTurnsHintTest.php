<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PhaseRun;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskMaxTurnsHintTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_max_turns_true_after_two_max_turns_runs(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->for($task)->create(['phase' => 'concept', 'iteration' => 1, 'stop_reason' => 'error_max_turns']);
        PhaseRun::factory()->for($task)->create(['phase' => 'concept', 'iteration' => 2, 'stop_reason' => 'error_max_turns']);

        $this->assertTrue($task->hasRepeatedMaxTurns('concept'));
        $this->assertFalse($task->hasRepeatedMaxTurns('implement'));
    }

    public function test_repeated_max_turns_false_below_threshold_or_other_reasons(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->for($task)->create(['phase' => 'concept', 'iteration' => 1, 'stop_reason' => 'error_max_turns']);
        PhaseRun::factory()->for($task)->create(['phase' => 'concept', 'iteration' => 2, 'stop_reason' => 'success']);

        $this->assertFalse($task->hasRepeatedMaxTurns('concept'));
    }
}

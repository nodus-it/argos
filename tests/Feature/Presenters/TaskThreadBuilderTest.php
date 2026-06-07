<?php

declare(strict_types=1);

namespace Tests\Feature\Presenters;

use App\Models\PhaseRun;
use App\Models\Task;
use App\Presenters\TaskThreadBuilder;
use App\Support\Workflow\TaskStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskThreadBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function build(Task $task): array
    {
        return app(TaskThreadBuilder::class)->build($task, TaskStage::for($task));
    }

    public function test_thread_opens_with_the_created_entry(): void
    {
        $task = Task::factory()->create(['description' => 'Build a thing']);

        $thread = $this->build($task);

        $this->assertSame('created', $thread[0]['kind']);
        $this->assertStringContainsString('Build a thing', $thread[0]['body']);
    }

    public function test_each_phase_run_becomes_a_phase_item(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'concept', 'iteration' => 1]);
        PhaseRun::factory()->create(['task_id' => $task->id, 'phase' => 'implement', 'iteration' => 1]);

        $phaseItems = array_values(array_filter($this->build($task), fn (array $i): bool => $i['kind'] === 'phase'));

        $this->assertCount(2, $phaseItems);
        $this->assertSame('concept', $phaseItems[0]['phase']);
        $this->assertSame('implement', $phaseItems[1]['phase']);
    }

    public function test_feedback_notes_are_interleaved_before_their_run(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 2,
            'implement_notes' => 'Please also handle the empty case.',
        ]);

        $kinds = array_column($this->build($task), 'kind');

        // created → feedback → phase
        $this->assertSame(['created', 'feedback', 'phase'], $kinds);
    }

    public function test_failed_quality_gate_resolves_to_its_latest_log_key(): void
    {
        $task = Task::factory()->create();
        PhaseRun::factory()->create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'result_json' => ['quality_gates' => ['phpstan' => 'fail', 'pint' => 'pass']],
            'quality_gate_logs' => ['phpstan.1' => 'a', 'phpstan.2' => 'b', 'pint.1' => 'c'],
        ]);

        $item = array_values(array_filter($this->build($task), fn (array $i): bool => $i['kind'] === 'phase'))[0];

        // The failed gate links to its newest log key; the passing one is omitted.
        $this->assertSame('phpstan.2', $item['qualityGateLastKeys']['phpstan']);
        $this->assertArrayNotHasKey('pint', $item['qualityGateLastKeys']);
    }
}

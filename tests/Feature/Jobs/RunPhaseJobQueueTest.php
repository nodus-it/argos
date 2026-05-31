<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunPhaseJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunPhaseJobQueueTest extends TestCase
{
    public function test_run_phase_job_is_dispatched_on_tasks_queue(): void
    {
        Queue::fake();

        RunPhaseJob::dispatch('task-id', 'concept');

        Queue::assertPushedOn('tasks', RunPhaseJob::class);
    }

    public function test_run_phase_job_targets_tasks_queue(): void
    {
        $job = new RunPhaseJob('task-id', 'concept');

        $this->assertSame('tasks', $job->queue);
    }
}

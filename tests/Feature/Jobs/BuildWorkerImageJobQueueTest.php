<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\AgentName;
use App\Jobs\BuildWorkerImageJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BuildWorkerImageJobQueueTest extends TestCase
{
    public function test_build_worker_image_job_is_dispatched(): void
    {
        Queue::fake();

        BuildWorkerImageJob::dispatch('stack-id', AgentName::ClaudeCode);

        Queue::assertPushed(BuildWorkerImageJob::class);
    }

    public function test_build_worker_image_job_has_no_explicit_queue_override(): void
    {
        $job = new BuildWorkerImageJob('stack-id', AgentName::ClaudeCode);

        // No $queue property — job uses the connection's configured default queue.
        $this->assertFalse(isset($job->queue));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\BuildWorkerImageJob;
use App\Jobs\Workspace\ProvisionWorkspaceJob;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the queue invariant: a connection whose retry_after is below a
 * single-try job's timeout re-reserves the still-running job
 * (tries=1 → MaxAttemptsExceededException) while its container keeps working.
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_retry_after_exceeds_the_longest_job_timeout_on_every_real_connection(): void
    {
        $jobTimeout = max(array_map(
            fn (string $job): int => (int) (new ReflectionClass($job))->getProperty('timeout')->getDefaultValue(),
            [ProvisionWorkspaceJob::class, BuildWorkerImageJob::class],
        ));

        foreach (['redis', 'database'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan(
                $jobTimeout,
                $retryAfter,
                "queue connection [{$connection}] retry_after ({$retryAfter}s) must exceed the longest job timeout ({$jobTimeout}s), "
                .'otherwise a still-running job is re-reserved and fails with MaxAttemptsExceededException.',
            );
        }
    }
}

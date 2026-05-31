<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunPhaseJob;
use Tests\TestCase;

/**
 * Guards the queue invariant that broke long phase runs: a connection whose
 * retry_after is below RunPhaseJob's timeout re-reserves the still-running job
 * (tries=1 → MaxAttemptsExceededException), marking the task failed while the
 * worker container is still going.
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_retry_after_exceeds_the_phase_job_timeout_on_every_real_connection(): void
    {
        $jobTimeout = (new RunPhaseJob('01ksz00000000000000000test', 'concept'))->timeout;

        foreach (['redis', 'database'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan(
                $jobTimeout,
                $retryAfter,
                "queue connection [{$connection}] retry_after ({$retryAfter}s) must exceed the RunPhaseJob timeout ({$jobTimeout}s), "
                .'otherwise a still-running phase is re-reserved and fails with MaxAttemptsExceededException.',
            );
        }
    }
}

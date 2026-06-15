<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReapOrphanedRunsJob;
use Illuminate\Console\Command;

/**
 * Sweeps orphaned per-run Docker resources (worker + sidecar containers, run
 * networks) left behind when a phase job was hard-killed and never reached its
 * finally-block teardown. Runs on the scheduler, which has no docker socket —
 * so it only DISPATCHES ReapOrphanedRunsJob; the queue worker (which does have
 * the socket) performs the actual reaping.
 */
class CleanupOrphanedRuns extends Command
{
    protected $signature = 'argos:cleanup-orphans';

    protected $description = 'Reap orphaned worker/sidecar run resources (dispatches the sweep to the queue).';

    public function handle(): int
    {
        ReapOrphanedRunsJob::dispatch();
        $this->info('Dispatched orphaned-run sweep to the queue.');

        return self::SUCCESS;
    }
}

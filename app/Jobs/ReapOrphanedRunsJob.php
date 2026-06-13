<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PhaseStatus;
use App\Models\Task;
use App\Services\Workflow\RunResourceReaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reaps the per-run Docker resources (worker + sidecar containers, run network)
 * of every task that is NOT currently running a phase — anything left behind by
 * a hard process kill that skipped the runner's finally-block teardown. Runs on
 * the queue worker because it shells out to docker; the scheduler that triggers
 * it (argos:cleanup-orphans) has no socket.
 *
 * A task with current_status=running legitimately owns its containers and is
 * kept; current_status stays 'running' for the whole phase including the window
 * before the container is up, so an in-flight run is never reaped by mistake.
 */
class ReapOrphanedRunsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('tasks');
    }

    public function handle(RunResourceReaper $reaper): void
    {
        $keep = Task::query()
            ->where('current_status', PhaseStatus::Running->value)
            ->pluck('id')
            ->all();

        $reaper->reapExcept($keep);
    }
}

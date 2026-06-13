<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Task\TaskTeardown;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Drops every Docker-side resource of a deleted task (workspace volume, demo
 * stack + route, run containers/network). Carries the resolved identifiers as
 * values rather than a task id to look up — the Task row is already gone by the
 * time this runs, so there is nothing left to load. Runs on the queue worker
 * (which owns the docker socket); never on the web request that deletes.
 */
class TeardownTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly string $taskId,
        public readonly string $volumeName,
        public readonly string $demoSlug,
    ) {
        $this->onQueue('tasks');
    }

    public function handle(TaskTeardown $teardown): void
    {
        $teardown->purge($this->taskId, $this->volumeName, $this->demoSlug);
    }
}

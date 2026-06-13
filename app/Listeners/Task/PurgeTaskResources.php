<?php

declare(strict_types=1);

namespace App\Listeners\Task;

use App\Events\Task\TaskDeleted;
use App\Jobs\TeardownTaskJob;
use App\Services\Demo\DemoDeployer;

/**
 * On task delete, queue a full Docker teardown. The identifiers are resolved
 * here while the (in-memory) Task is still available and handed to the job as
 * values — the row itself is already deleted, so the job cannot look it up.
 * Keeping the docker side-effect in a listener leaves TaskService a pure-DB
 * service (mirrors RemoveTaskVolume on TaskCompleted).
 */
final class PurgeTaskResources
{
    public function __construct(private readonly DemoDeployer $demoDeployer) {}

    public function handle(TaskDeleted $event): void
    {
        $task = $event->task;

        TeardownTaskJob::dispatch(
            $task->id,
            $task->volumeName(),
            $this->demoDeployer->demoSlug($task),
        );
    }
}

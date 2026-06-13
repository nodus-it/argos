<?php

declare(strict_types=1);

namespace App\Listeners\Task;

use App\Events\Task\TaskCompleted;
use App\Services\Task\TaskTeardown;

/**
 * Drop the task's workspace volume once it is completed. Keeping the docker
 * side-effect out of TaskService leaves that a pure DB service. The demo (if
 * any) keeps running for preview, so this only drops the volume — full teardown
 * happens on delete (PurgeTaskResources).
 */
final class RemoveTaskVolume
{
    public function __construct(private readonly TaskTeardown $teardown) {}

    public function handle(TaskCompleted $event): void
    {
        $this->teardown->removeVolume($event->task->volumeName());
    }
}

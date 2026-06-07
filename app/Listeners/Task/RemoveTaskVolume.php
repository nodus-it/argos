<?php

declare(strict_types=1);

namespace App\Listeners\Task;

use App\Events\Task\TaskCompleted;
use Illuminate\Support\Facades\Process;

/**
 * Drop the task's workspace volume once it is completed. Keeping the docker
 * side-effect out of TaskService leaves that a pure DB service.
 */
final class RemoveTaskVolume
{
    public function handle(TaskCompleted $event): void
    {
        Process::run(['docker', 'volume', 'rm', $event->task->volumeName()]);
    }
}

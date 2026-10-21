<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Task;
use App\Services\Workspace\TaskPreparer;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Prepares a new task's working copy (see TaskPreparer). Waits for the
 * workspace container to come up by releasing itself.
 */
class PrepareTaskJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $taskId)
    {
        $this->onQueue('tasks');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(TaskPreparer $preparer): void
    {
        $task = Task::query()->with('workspace')->find($this->taskId);
        if ($task === null) {
            return;
        }

        if (! $preparer->prepare($task)) {
            $this->release(TaskPreparer::RETRY_SECONDS);
        }
    }
}

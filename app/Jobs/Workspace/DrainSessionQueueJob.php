<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Services\Workspace\TaskService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A session freed up in a workspace: hand it to the waiting tasks (see TaskService::drainQueue). */
class DrainSessionQueueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 30;

    public function __construct(public readonly string $workspaceId) {}

    public function uniqueId(): string
    {
        return $this->workspaceId;
    }

    public function handle(TaskService $tasks): void
    {
        $tasks->drainQueue($this->workspaceId);
    }
}

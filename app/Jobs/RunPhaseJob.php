<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Phase\PhaseRunner;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPhaseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $taskId,
        public readonly string $phase,
        public readonly array $flags = [],
    ) {}

    public function handle(PhaseRunner $runner): void
    {
        $task = Task::findOrFail($this->taskId);
        $runner->runBlocking($task, $this->phase, $this->flags);

        $task->refresh();
        $task->advanceWorkflow($this->phase, $task->current_status ?? 'failed');
    }
}

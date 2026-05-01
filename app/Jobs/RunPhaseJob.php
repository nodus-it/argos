<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Phase\PhaseRunner;
use App\Domain\Phase\StateReader;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPhaseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $taskId,
        public readonly string $phase,
        public readonly array $flags = [],
    ) {}

    public function handle(PhaseRunner $runner, StateReader $stateReader): void
    {
        $task = Task::findOrFail($this->taskId);
        $runner->runBlocking($task, $this->phase, $this->flags);

        $task->refresh();
        $stateReader->syncToDb($task);
    }
}

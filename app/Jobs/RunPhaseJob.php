<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Phase\PhaseRunner;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        Log::channel('argos')->info('Job dispatched', ['task' => $this->taskId, 'phase' => $this->phase]);

        try {
            $task = Task::findOrFail($this->taskId);
            $runner->runBlocking($task, $this->phase, $this->flags);

            $task->refresh();
            $task->advanceWorkflow($this->phase, $task->current_status ?? 'failed');

            Log::channel('argos')->info('Job completed', ['task' => $this->taskId, 'phase' => $this->phase, 'status' => $task->current_status]);
        } catch (\Throwable $e) {
            Log::channel('argos')->error('Job failed with exception', [
                'task' => $this->taskId,
                'phase' => $this->phase,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            throw $e;
        }
    }
}

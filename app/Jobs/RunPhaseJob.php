<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Phase\PhaseRunner;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        // Hold back while a usage limit is active — release back to the queue.
        $limit = Cache::get('usage_limit');
        if (is_array($limit) && ($limit['active'] ?? false)) {
            $resetAt = isset($limit['reset_at']) ? Carbon::parse($limit['reset_at']) : null;
            $delaySec = ($resetAt !== null && $resetAt->isFuture())
                ? max(60, (int) now()->diffInSeconds($resetAt))
                : 900;

            Log::channel('argos')->info('Job held back: usage limit active', [
                'task' => $this->taskId,
                'phase' => $this->phase,
                'retry_in_seconds' => $delaySec,
            ]);

            $this->release($delaySec);

            return;
        }

        Log::channel('argos')->info('Job dispatched', ['task' => $this->taskId, 'phase' => $this->phase]);

        try {
            $task = Task::findOrFail($this->taskId);
            $runner->runBlocking($task, $this->phase, $this->flags);

            $task->refresh();

            // Phase hit the usage limit — re-schedule instead of failing permanently.
            if ($task->current_status === 'rate_limited') {
                $retryLimit = Cache::get('usage_limit');
                $resetAt = isset($retryLimit['reset_at']) ? Carbon::parse($retryLimit['reset_at']) : null;
                $delaySec = ($resetAt !== null && $resetAt->isFuture())
                    ? max(60, (int) now()->diffInSeconds($resetAt))
                    : 900;

                Log::channel('argos')->info('Phase rate-limited, re-scheduling', [
                    'task' => $this->taskId,
                    'phase' => $this->phase,
                    'retry_in_seconds' => $delaySec,
                ]);

                static::dispatch($this->taskId, $this->phase, $this->flags)
                    ->delay(now()->addSeconds($delaySec));

                return;
            }

            $task->advanceWorkflow($this->phase, $task->current_status ?? 'failed');

            Log::channel('argos')->info('Job completed', [
                'task' => $this->taskId,
                'phase' => $this->phase,
                'status' => $task->current_status,
            ]);
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

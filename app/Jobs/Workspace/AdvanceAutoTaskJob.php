<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Services\Workspace\AutoTaskDriver;
use App\Services\Workspace\AutoTaskRetry;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of an auto-task (see AutoTaskDriver::step). Waiting steps
 * release themselves and come back; the driver gives up on its own after
 * its timeouts, retryUntil() is only the safety net.
 */
class AdvanceAutoTaskJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $taskId,
        public readonly string $step,
        public readonly array $data = [],
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(AutoTaskDriver $driver): void
    {
        try {
            $driver->step($this->taskId, $this->step, $this->data);
        } catch (AutoTaskRetry $retry) {
            if (! $retry->giveUp) {
                $this->release($retry->delaySeconds);
            }
        }
    }
}

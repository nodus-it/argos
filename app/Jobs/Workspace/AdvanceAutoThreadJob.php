<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Services\Workspace\AutoThreadDriver;
use App\Services\Workspace\AutoThreadRetry;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of an auto-thread (see AutoThreadDriver::step). Waiting steps
 * release themselves and come back; the driver gives up on its own after
 * its timeouts, retryUntil() is only the safety net.
 */
class AdvanceAutoThreadJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $threadId,
        public readonly string $step,
        public readonly array $data = [],
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(AutoThreadDriver $driver): void
    {
        try {
            $driver->step($this->threadId, $this->step, $this->data);
        } catch (AutoThreadRetry $retry) {
            if (! $retry->giveUp) {
                $this->release($retry->delaySeconds);
            }
        }
    }
}

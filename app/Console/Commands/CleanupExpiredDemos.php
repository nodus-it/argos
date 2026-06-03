<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DemoStatus;
use App\Jobs\StopDemoJob;
use App\Models\Demo;
use Illuminate\Console\Command;

/**
 * Tears down live demos whose TTL has elapsed. Runs on the scheduler, which has
 * no docker socket — so it only DISPATCHES StopDemoJob; the queue worker (which
 * does have the socket) performs the actual `docker compose down`.
 */
class CleanupExpiredDemos extends Command
{
    protected $signature = 'argos:cleanup-demos';

    protected $description = 'Stop live demos whose TTL has elapsed (dispatches teardown to the queue).';

    public function handle(): int
    {
        $expired = Demo::query()
            ->whereIn('status', [DemoStatus::Building->value, DemoStatus::Live->value])
            ->whereNotNull('ttl_until')
            ->where('ttl_until', '<=', now())
            ->get();

        foreach ($expired as $demo) {
            StopDemoJob::dispatch($demo->task_id);
            $this->info("Dispatched teardown for expired demo {$demo->id} (task {$demo->task_id}).");
        }

        if ($expired->isEmpty()) {
            $this->info('No expired demos.');
        }

        return self::SUCCESS;
    }
}

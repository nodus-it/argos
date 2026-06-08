<?php

declare(strict_types=1);

namespace App\Listeners\Task;

use App\Enums\DemoStatus;
use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Events\Task\PhaseCompleted;
use App\Jobs\StopDemoJob;

/**
 * Once the PR is created, tear the live demo down — it was a pre-PR preview.
 * It stays restartable anytime from the detail view (M6).
 */
final class StopDemoAfterPush
{
    public function handle(PhaseCompleted $event): void
    {
        if ($event->phase !== Phase::Push || $event->status !== PhaseStatus::Completed) {
            return;
        }

        $demo = $event->task->currentDemo();
        if ($demo !== null && in_array($demo->status, [DemoStatus::Building, DemoStatus::Live], true)) {
            StopDemoJob::dispatch($event->task->id);
        }
    }
}

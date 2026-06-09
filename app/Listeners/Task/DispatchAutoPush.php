<?php

declare(strict_types=1);

namespace App\Listeners\Task;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Events\Task\PhaseCompleted;
use App\Jobs\RunPhaseJob;

/**
 * After a successful implement, chain into push when the repo opts into
 * auto-PR. workflow_status already reflects ImplementCompleted by the time
 * this runs; while push runs, current_phase/current_status carry the live
 * progress and push completion advances workflow_status to InReview.
 */
final class DispatchAutoPush
{
    public function handle(PhaseCompleted $event): void
    {
        if ($event->phase === Phase::Implement
            && $event->status === PhaseStatus::Completed
            && $event->task->repoProfile?->auto_pr) {
            RunPhaseJob::dispatch($event->task->id, 'push');
        }
    }
}

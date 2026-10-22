<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Workspace;
use App\Services\Workspace\TaskSessionReconciler;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask every running workspace's daemon which sessions it holds and close
 * the ones it lost (see TaskSessionReconciler). Runs on the queue: the
 * scheduler is not on the workspace network.
 */
class ReconcileTaskSessionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(WorkspaceDaemonClient $daemon, TaskSessionReconciler $reconciler): void
    {
        Workspace::query()->where('status', WorkspaceStatus::Running->value)->each(function (Workspace $workspace) use ($daemon, $reconciler): void {
            try {
                $health = $daemon->for($workspace, 5)->health();
            } catch (DaemonTransportException) {
                // Unreachable for now (restarting): the next sweep sees the new daemon.
                return;
            }
            $reconciler->reconcile($workspace, $health);
        });
    }
}

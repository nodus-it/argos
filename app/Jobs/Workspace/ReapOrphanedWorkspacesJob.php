<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContainerManager;
use App\Services\Workspace\WorkspaceTeardown;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Removes workspace containers (label argos.workspace) that no live
 * workspace row claims any more — e.g. after a crash mid-delete.
 */
class ReapOrphanedWorkspacesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(WorkspaceContainerManager $containers, WorkspaceTeardown $teardown): void
    {
        $slugs = $containers->containerSlugs();
        if ($slugs === []) {
            return;
        }

        $known = Workspace::query()->whereIn('slug', $slugs)->pluck('slug')->all();
        foreach (array_diff($slugs, $known) as $slug) {
            Log::channel('argos')->info('Reaping orphaned workspace resources', ['slug' => $slug]);
            $teardown->purge($slug);
        }
    }
}

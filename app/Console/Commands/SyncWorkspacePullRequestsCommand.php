<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Console\Command;

/** Refreshes the state (open/merged/closed) of workspaces' open pull requests. */
class SyncWorkspacePullRequestsCommand extends Command
{
    protected $signature = 'argos:workspaces:sync-prs';

    protected $description = 'Refresh the pull request state of workspaces with an open pull request.';

    public function handle(WorkspaceShipService $ship): int
    {
        Workspace::query()
            ->whereNotNull('pr_number')
            ->where('pr_state', 'open')
            ->each(fn (Workspace $workspace) => $ship->syncPullRequest($workspace));

        return self::SUCCESS;
    }
}

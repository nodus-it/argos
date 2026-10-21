<?php

declare(strict_types=1);

namespace App\Jobs\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceSetupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A new workspace: inspect the repo, pick the stack, start the setup task if needed. */
class SetUpWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $workspaceId, public readonly string $userId) {}

    public function handle(WorkspaceSetupService $setup): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        $user = User::query()->find($this->userId);
        if ($workspace !== null && $user !== null) {
            $setup->afterCreate($workspace, $user);
        }
    }
}

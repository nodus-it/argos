<?php

declare(strict_types=1);

namespace App\Testing;

use App\Enums\ThreadStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceProvisioner;

/** Brings workspaces up and down instantly — no Docker in E2E fake mode. */
class FakeWorkspaceProvisioner extends WorkspaceProvisioner
{
    public function __construct() {}

    public function boot(Workspace $workspace): void
    {
        $workspace->forceFill([
            'status' => WorkspaceStatus::Running,
            'status_message' => null,
            'container_name' => $workspace->containerName(),
            'last_activity_at' => now(),
        ])->save();
    }

    public function shutdown(Workspace $workspace): void
    {
        Thread::query()->where('workspace_id', $workspace->id)->update(['session_open' => false, 'status' => ThreadStatus::Closed->value]);
        $workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();
    }
}

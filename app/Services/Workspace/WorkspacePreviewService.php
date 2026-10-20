<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\DemoStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\DeployWorkspaceDemoJob;
use App\Jobs\Workspace\RerunWorkspaceDemoCommandsJob;
use App\Models\Demo;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Demo\DemoDeployer;
use Illuminate\Support\Facades\Gate;

/**
 * The live preview of a workspace: the regular demo system pointed at the
 * workspace volume, members-only, started on demand.
 */
class WorkspacePreviewService
{
    public function __construct(private readonly DemoDeployer $deployer) {}

    /** Why previews are unavailable for this workspace, or null when they are. */
    public function unavailableReason(Workspace $workspace): ?string
    {
        return match (true) {
            ! (bool) config('argos.preview.enabled') => __('workspaces.preview.disabled_platform'),
            ! (bool) $workspace->repoProfile->live_demo_enabled => __('workspaces.preview.disabled_project'),
            $workspace->status !== WorkspaceStatus::Running => __('workspaces.tabs.not_running'),
            default => null,
        };
    }

    /** Build or rebuild the preview (replaces a running one). */
    public function start(Workspace $workspace, User $by): void
    {
        Gate::forUser($by)->authorize('operate', $workspace);
        $reason = $this->unavailableReason($workspace);
        if ($reason !== null) {
            throw new WorkspaceException($reason);
        }

        DeployWorkspaceDemoJob::dispatch($workspace->id);
    }

    public function rerunCommands(Workspace $workspace, User $by): void
    {
        Gate::forUser($by)->authorize('operate', $workspace);
        if ($workspace->currentDemo()?->status !== DemoStatus::Live) {
            throw WorkspaceException::translated('workspaces.preview.not_live');
        }

        RerunWorkspaceDemoCommandsJob::dispatch($workspace->id);
    }

    public function stop(Workspace $workspace, ?User $by = null): void
    {
        if ($by !== null) {
            Gate::forUser($by)->authorize('operate', $workspace);
        }

        $this->deployer->teardown($workspace);
        Demo::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [DemoStatus::Building->value, DemoStatus::Live->value])
            ->update(['status' => DemoStatus::Stopped->value, 'url' => null]);
    }
}

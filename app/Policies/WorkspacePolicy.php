<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Capability;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\TaskAccess;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Support\Facades\Gate;

/**
 * Workspace access is membership-based; only Argos admins create
 * workspaces. Capabilities come from the role (see TaskAccess).
 */
class WorkspacePolicy
{
    public function __construct(private readonly TaskAccess $access) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->access->canView($user, $workspace);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Let Claude run every tool without asking (permission mode "bypass"): workspace admins and Argos administrators. */
    public function bypassPermissions(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin() || $workspace->roleOf($user) === WorkspaceRole::Admin;
    }

    /** Settings, secrets, integrations. */
    public function update(User $user, Workspace $workspace): bool
    {
        return $this->access->allows($user, Capability::WorkspaceManage, $workspace);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace)
            && Gate::forUser($user)->allows(WorkspaceMemberService::COLLABORATION_GATE);
    }

    /** Run the setup task, rebuild the image. */
    public function setup(User $user, Workspace $workspace): bool
    {
        return $this->access->allows($user, Capability::WorkspaceSetup, $workspace);
    }

    /** Open VS Code. */
    public function editor(User $user, Workspace $workspace): bool
    {
        return $this->access->allows($user, Capability::WorkspaceEditor, $workspace);
    }

    /** Start/stop the container. */
    public function operate(User $user, Workspace $workspace): bool
    {
        return $this->access->allows($user, Capability::WorkspaceOperate, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return false;
    }

    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return false;
    }
}

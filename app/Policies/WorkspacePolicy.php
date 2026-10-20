<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Support\Facades\Gate;

/**
 * Workspace access is membership-based. Admins can see and manage every
 * workspace, but to work in one (prompt, approve) they join it as a member
 * like anyone else — see ThreadPolicy.
 */
class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) config('argos.workspaces.enabled', true);
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin() || $workspace->roleOf($user) !== null;
    }

    public function create(User $user): bool
    {
        return (bool) config('argos.workspaces.enabled', true);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin() || $workspace->roleOf($user) === WorkspaceRole::Owner;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace)
            && Gate::forUser($user)->allows(WorkspaceMemberService::COLLABORATION_GATE);
    }

    /** Start/stop the container, run the preview. */
    public function operate(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin() || ($workspace->roleOf($user)?->canOperate() ?? false);
    }

    /** Commit, push and open/update the pull request. */
    public function ship(User $user, Workspace $workspace): bool
    {
        return $this->operate($user, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace);
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

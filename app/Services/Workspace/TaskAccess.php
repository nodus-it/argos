<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\Capability;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

/**
 * The one place that answers "may this person do X here" (policies ask it).
 *
 * - Argos admins may do everything.
 * - A workspace role grants its capability set across the workspace
 *   (admins: all; members: create tasks, start the container, editor).
 * - On a task, its owner and invited collaborators may write, approve,
 *   review and ship; inviting, handing over and archiving stay with the
 *   owner. Everyone in the workspace can read every task.
 */
class TaskAccess
{
    public function allows(User $user, Capability $capability, Workspace|Task $subject): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $workspace = $subject instanceof Task ? $subject->workspace : $subject;
        if ($workspace->roleOf($user)?->grants($capability) ?? false) {
            return true;
        }

        if (! $subject instanceof Task) {
            return false;
        }

        return match (true) {
            $capability->isOwnerOnly() => $subject->isOwnedBy($user),
            $capability->isCollaborative() => $subject->involves($user),
            default => false,
        };
    }

    /** Members of any role (and Argos admins) read every task of the workspace. */
    public function canView(User $user, Workspace $workspace): bool
    {
        return $user->isAdmin() || $workspace->roleOf($user) !== null;
    }
}

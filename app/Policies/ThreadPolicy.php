<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AgentPool;

/**
 * Working in a thread requires an operating role (owner/editor) in its
 * workspace. Viewers and non-member admins can read, not act.
 */
class ThreadPolicy
{
    public function view(User $user, Thread $thread): bool
    {
        return $user->isAdmin() || $thread->workspace->roleOf($user) !== null;
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->operates($user, $workspace)
            && app(AgentPool::class)->usableAgentsFor($user)->isNotEmpty();
    }

    public function prompt(User $user, Thread $thread): bool
    {
        return $this->operates($user, $thread->workspace);
    }

    public function interrupt(User $user, Thread $thread): bool
    {
        return $this->operates($user, $thread->workspace);
    }

    public function resolveApproval(User $user, Thread $thread): bool
    {
        return $this->operates($user, $thread->workspace);
    }

    public function changeMode(User $user, Thread $thread): bool
    {
        return $this->operates($user, $thread->workspace);
    }

    public function update(User $user, Thread $thread): bool
    {
        return $this->operates($user, $thread->workspace);
    }

    private function operates(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->canOperate() ?? false;
    }
}

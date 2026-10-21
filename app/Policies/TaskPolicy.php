<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Capability;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AgentPool;
use App\Services\Workspace\TaskAccess;

/**
 * Every member reads every task; acting in one needs the matching
 * capability (see TaskAccess): owners and collaborators write, approve,
 * review and ship, owners invite, hand over and archive.
 */
class TaskPolicy
{
    public function __construct(private readonly TaskAccess $access) {}

    public function view(User $user, Task $task): bool
    {
        return $this->access->canView($user, $task->workspace);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $this->access->allows($user, Capability::TaskCreate, $workspace)
            && app(AgentPool::class)->usableAgentsFor($user)->isNotEmpty();
    }

    public function prompt(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskWrite, $task);
    }

    public function interrupt(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskWrite, $task);
    }

    public function resolveApproval(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskApprove, $task);
    }

    public function changeMode(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskWrite, $task);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskWrite, $task);
    }

    /** Commit, push and open/update the pull request. */
    public function ship(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskShip, $task);
    }

    public function review(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskReview, $task);
    }

    public function invite(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskInvite, $task);
    }

    public function transfer(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskTransfer, $task);
    }

    public function archive(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskArchive, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->access->allows($user, Capability::TaskArchive, $task);
    }
}

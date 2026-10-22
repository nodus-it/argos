<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\Capability;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskMember;
use App\Models\User;
use App\Services\IssueTracker\IssueRunReporter;
use Illuminate\Support\Facades\Gate;

/**
 * Who works on a task: inviting and removing collaborators, handing the
 * task to another member (it moves to their agent), and who qualifies.
 */
class TaskMembershipService
{
    public function __construct(
        private readonly AgentPool $pool,
        private readonly TaskService $tasks,
        private readonly TaskNoticeService $notices,
    ) {}

    /**
     * Who can be invited to a task: workspace members besides the owner and
     * the ones already in it.
     *
     * @return array<int, string> user id => name
     */
    public function inviteCandidates(Task $task): array
    {
        return $task->workspace->users()
            ->whereKeyNot($task->owner_user_id)
            ->whereNotIn('users.id', $task->members()->pluck('user_id'))
            ->orderBy('name')
            ->pluck('name', 'users.id')
            ->all();
    }

    /**
     * Who a task can be handed to: members whose role may create tasks and
     * who have an agent the task can run on (what transfer() checks).
     *
     * @return array<int, string> user id => name
     */
    public function transferCandidates(Task $task): array
    {
        return $task->workspace->users()->whereKeyNot($task->owner_user_id)->orderBy('name')->get()
            ->filter(fn (User $user): bool => ($task->workspace->roleOf($user)?->grants(Capability::TaskCreate) ?? false) && $this->pool->usableAgentsFor($user)->isNotEmpty())
            ->pluck('name', 'id')
            ->all();
    }

    public function invite(Task $task, User $by, User $member): void
    {
        Gate::forUser($by)->authorize('invite', $task);
        // Working together on a task is pair programming: behind the same gate as workspace members.
        if (! app(WorkspaceMemberService::class)->collaborationEnabled($by)) {
            throw WorkspaceException::translated('workspaces.errors.collaboration_disabled');
        }
        if ($task->workspace->roleOf($member) === null) {
            throw WorkspaceException::translated('workspaces.errors.not_a_member', ['name' => $member->name]);
        }
        if ($task->isOwnedBy($member) || $task->members()->where('user_id', $member->id)->exists()) {
            return;
        }

        TaskMember::query()->create(['task_id' => $task->id, 'user_id' => $member->id, 'invited_by' => $by->id]);
        $this->notices->notice($task, 'invited', $by, ['member_name' => $member->name]);
        app(WorkspaceNotifier::class)->notify($task->workspace, $task, 'invite', __('workspaces.notifications.invite', ['name' => $by->name]), $task->title, $by->id);
    }

    public function uninvite(Task $task, User $by, User $member): void
    {
        Gate::forUser($by)->authorize('invite', $task);
        if ($task->members()->where('user_id', $member->id)->delete() > 0) {
            $this->notices->notice($task, 'uninvited', $by, ['member_name' => $member->name]);
        }
    }

    /**
     * Hand the task to another person: from now on it runs on their
     * subscription (the open session ends). The previous owner stays on as a
     * collaborator.
     */
    public function transfer(Task $task, User $by, User $newOwner, ?AgentCredential $agent = null): void
    {
        Gate::forUser($by)->authorize('transfer', $task);
        if ($task->isOwnedBy($newOwner)) {
            return;
        }
        $role = $task->workspace->roleOf($newOwner);
        if ($role === null || ! $role->grants(Capability::TaskCreate)) {
            throw WorkspaceException::translated('workspaces.errors.transfer_needs_member', ['name' => $newOwner->name]);
        }
        // Own agents come first (AgentPool orders them so).
        $agent ??= $this->pool->usableAgentsFor($newOwner)->first();
        if ($agent === null || ! Gate::forUser($newOwner)->allows('use', $agent)) {
            throw WorkspaceException::translated('workspaces.errors.transfer_needs_agent', ['name' => $newOwner->name]);
        }

        if ($task->session_open) {
            $this->tasks->close($task);
        }
        $previous = $task->owner;
        $task->forceFill(['owner_user_id' => $newOwner->id, 'agent_credential_id' => $agent->id])->save();
        $task->members()->where('user_id', $newOwner->id)->delete();
        if ($previous !== null) {
            TaskMember::query()->firstOrCreate(['task_id' => $task->id, 'user_id' => $previous->id], ['invited_by' => $by->id]);
        }
        $task->unsetRelation('members');

        $this->notices->notice($task, 'transferred', $by, ['owner_name' => $newOwner->name]);
        app(IssueRunReporter::class)->ownerChanged($task, $newOwner);
        app(WorkspaceNotifier::class)->notify($task->workspace, $task, 'invite', __('workspaces.notifications.transferred', ['name' => $by->name]), $task->title, $by->id);
    }
}

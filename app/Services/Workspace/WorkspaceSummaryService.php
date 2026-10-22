<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalStatus;
use App\Enums\AutoTaskState;
use App\Enums\DemoStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Models\Demo;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** "What is going on" per workspace, for the dashboard and the list. */
class WorkspaceSummaryService
{
    /**
     * @return array{working: int, waiting: int, tasks: int, waiting_for_me: bool, pr_open: int, previews: int}
     */
    public function for(Workspace $workspace, User $user): array
    {
        $tasks = Task::query()->where('workspace_id', $workspace->id)->whereNull('archived_at')->get(['id', 'status', 'session_status', 'owner_user_id']);
        $waiting = $tasks->where('session_status', SessionStatus::WaitingInput)->count();
        // Only the people of a task decide — "waiting for me" means my tasks or ones I was invited to.
        $mine = $tasks->filter(fn (Task $task): bool => $task->involves($user))->pluck('id');
        $pending = TaskApproval::query()->whereIn('task_id', $mine)->where('status', ApprovalStatus::Pending->value)->exists();

        return [
            'working' => $tasks->where('session_status', SessionStatus::Running)->count(),
            'waiting' => $waiting,
            'tasks' => $tasks->count(),
            'waiting_for_me' => $pending,
            'pr_open' => $tasks->where('status', TaskStatus::PrOpen)->count(),
            'previews' => Demo::query()->where('workspace_id', $workspace->id)->where('status', DemoStatus::Live->value)->count(),
        ];
    }

    /**
     * Workspaces the user can open, in rail order (by name, ignoring case): Argos admins see
     * every workspace, everybody else the ones they are a member of.
     *
     * @return Collection<int, Workspace>
     */
    public function visibleWorkspaces(User $user): Collection
    {
        return Workspace::query()
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->whereHas('members', fn (Builder $m) => $m->where('user_id', $user->id)))
            ->get()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Open tasks the user owns or was invited to (not archived, not finished),
     * in workspaces they can still open.
     *
     * @return Builder<Task>
     */
    public function involvedTasks(User $user): Builder
    {
        return Task::query()
            ->whereNull('archived_at')
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->where(fn (Builder $q) => $q->where('owner_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id)))
            ->when(! $user->isAdmin(), fn (Builder $q) => $q->whereHas('workspace.members', fn (Builder $m) => $m->where('user_id', $user->id)));
    }

    /**
     * Restricts a task query to tasks waiting for a person: an open approval
     * (tool, plan or question), a session waiting for input, or an auto plan
     * awaiting approval.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function whereWaiting(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('session_status', SessionStatus::WaitingInput->value)
            ->orWhere('auto_state', AutoTaskState::AwaitingPlanApproval->value)
            ->orWhereHas('approvals', fn (Builder $a) => $a->where('status', ApprovalStatus::Pending->value)));
    }

    /**
     * Number of tasks waiting for this user, per workspace id — the rail badges.
     *
     * @return array<string, int>
     */
    public function waitingCountsFor(User $user): array
    {
        return $this->whereWaiting($this->involvedTasks($user))
            ->selectRaw('workspace_id, count(*) as waiting')
            ->groupBy('workspace_id')
            ->pluck('waiting', 'workspace_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}

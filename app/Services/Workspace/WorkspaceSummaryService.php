<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalStatus;
use App\Enums\DemoStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Models\Demo;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\User;
use App\Models\Workspace;
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
     * The user's workspaces, those waiting for them first, then working ones,
     * then by recent activity.
     *
     * @return Collection<int, array{workspace: Workspace, summary: array<string, mixed>}>
     */
    public function overviewFor(User $user, int $limit = 12): Collection
    {
        return $user->workspaces()->latest('last_activity_at')->limit(50)->get()
            ->map(fn (Workspace $workspace): array => ['workspace' => $workspace, 'summary' => $this->for($workspace, $user)])
            ->sortBy(fn (array $row): array => [
                $row['summary']['waiting_for_me'] ? 0 : 1,
                $row['summary']['working'] > 0 ? 0 : 1,
                -($row['workspace']->last_activity_at?->getTimestamp() ?? 0),
            ])
            ->take($limit)
            ->values();
    }
}

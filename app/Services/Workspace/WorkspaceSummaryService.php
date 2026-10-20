<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalStatus;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/** "What is going on" per workspace, for the dashboard and the list. */
class WorkspaceSummaryService
{
    /**
     * @return array{working: int, waiting: int, threads: int, waiting_for_me: bool, pr_state: string|null, preview: string|null}
     */
    public function for(Workspace $workspace, User $user): array
    {
        $threads = Thread::query()->where('workspace_id', $workspace->id)->whereNull('archived_at')->get(['id', 'status']);
        $waiting = $threads->where('status', ThreadStatus::WaitingInput)->count();
        $pending = ThreadApproval::query()->whereIn('thread_id', $threads->pluck('id'))->where('status', ApprovalStatus::Pending->value)->exists();

        return [
            'working' => $threads->where('status', ThreadStatus::Running)->count(),
            'waiting' => $waiting,
            'threads' => $threads->count(),
            // Only operators can decide — viewers are never "waited for".
            'waiting_for_me' => $pending && ($workspace->roleOf($user)?->canOperate() ?? false),
            'pr_state' => $workspace->pr_state,
            'preview' => $workspace->currentDemo()?->status->value,
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
        return $user->workspaces()->with('repoProfile')->latest('last_activity_at')->limit(50)->get()
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

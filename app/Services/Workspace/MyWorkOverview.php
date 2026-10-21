<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskState;
use App\Models\Task;
use App\Models\User;

/**
 * "My work" (/w home): the user's own and invited tasks across all
 * workspaces, grouped by what they need — waiting for the user first, then
 * running, PR open and ready (most recently active first in each group).
 */
class MyWorkOverview
{
    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const PR_OPEN = 'pr_open';

    public const READY = 'ready';

    /** Ready tasks are the long tail; only the most recently active ones show. */
    public const READY_LIMIT = 15;

    public function __construct(private readonly WorkspaceSummaryService $summary) {}

    /**
     * @return array{waiting: list<array{task: Task, state: TaskState}>, running: list<array{task: Task, state: TaskState}>, pr_open: list<array{task: Task, state: TaskState}>, ready: list<array{task: Task, state: TaskState}>, ready_more: int}
     */
    public function for(User $user): array
    {
        $waitingIds = $this->summary->whereWaiting($this->summary->involvedTasks($user))->pluck('id')->flip();
        $tasks = $this->summary->involvedTasks($user)
            ->with(['workspace:id,name,slug', 'owner:id,name'])
            ->latest('updated_at')
            ->limit(300)
            ->get();

        $groups = [self::WAITING => [], self::RUNNING => [], self::PR_OPEN => [], self::READY => []];
        foreach ($tasks as $task) {
            // A pending approval makes a task wait even while the session runs.
            $state = $waitingIds->has($task->id) ? TaskState::Waiting : $task->state();
            $group = match ($state) {
                TaskState::Waiting => self::WAITING,
                TaskState::Running, TaskState::Preparing => self::RUNNING,
                TaskState::PrOpen => self::PR_OPEN,
                default => self::READY,
            };
            $groups[$group][] = ['task' => $task, 'state' => $state];
        }

        $readyMore = max(0, count($groups[self::READY]) - self::READY_LIMIT);
        $groups[self::READY] = array_slice($groups[self::READY], 0, self::READY_LIMIT);

        return [...$groups, 'ready_more' => $readyMore];
    }
}

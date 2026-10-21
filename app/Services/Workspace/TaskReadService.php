<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskRead;
use App\Models\User;
use Illuminate\Support\Collection;

/** Per-member read position of tasks, for unread counters. */
class TaskReadService
{
    public function markRead(Task $task, User $user): void
    {
        $latest = (int) TaskEvent::query()->where('task_id', $task->id)->max('id');

        $read = TaskRead::query()->firstOrNew(['task_id' => $task->id, 'user_id' => $user->id]);
        if ($read->exists && $read->last_read_event_id >= $latest) {
            return;
        }
        $read->last_read_event_id = $latest;
        $read->save();
    }

    /**
     * Unread history entries per task id (assistant output, approvals,
     * errors and other people's messages — never one's own).
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<string, int>
     */
    public function unreadCounts(Collection $tasks, User $user): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }

        $reads = TaskRead::query()
            ->where('user_id', $user->id)
            ->whereIn('task_id', $tasks->pluck('id'))
            ->pluck('last_read_event_id', 'task_id');

        $counts = [];
        foreach ($tasks as $task) {
            $counts[$task->id] = TaskEvent::query()
                ->where('task_id', $task->id)
                ->where('id', '>', (int) ($reads[$task->id] ?? 0))
                ->where(fn ($q) => $q->whereNull('author_user_id')->orWhere('author_user_id', '!=', $user->id))
                ->whereNotIn('type', [TaskEventType::ToolResult->value, TaskEventType::Thinking->value, TaskEventType::StatusChanged->value])
                ->count();
        }

        return $counts;
    }
}

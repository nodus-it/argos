<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;

/**
 * The notices in a task's history ("Ada stopped Claude", "PR merged"):
 * stored and pushed to the open pages.
 */
class TaskNoticeService
{
    public function __construct(private readonly TaskStreamPublisher $publisher) {}

    /**
     * A notice nobody in particular caused (pull request changes, Argos
     * itself), persisted and broadcast.
     *
     * @param  array<string, mixed>  $extra
     */
    public function systemNotice(Task $task, string $kind, array $extra = []): void
    {
        $this->recordNotice($task, $kind, null, $extra);
    }

    /**
     * Persist a system notice (e.g. "Ada interrupted") in the history and
     * broadcast it.
     *
     * @param  array<string, mixed>  $extra
     */
    public function notice(Task $task, string $kind, User $by, array $extra = []): void
    {
        $this->recordNotice($task, $kind, $by, $extra);
    }

    /**
     * Store a notice in the history and tell the open pages (they re-render
     * it from the history; a system notice sends only its kind).
     *
     * @param  array<string, mixed>  $extra
     */
    private function recordNotice(Task $task, string $kind, ?User $by, array $extra): void
    {
        $payload = ['kind' => $kind, ...($by !== null ? ['author_name' => $by->name] : []), ...$extra];
        $event = TaskEvent::query()->create([
            'task_id' => $task->id,
            'type' => TaskEventType::Notice,
            'author_user_id' => $by?->id,
            'payload' => $payload,
        ]);

        $this->publisher->publish($task->workspace_id, $task->id, [[
            'type' => 'notice',
            'event_id' => $event->id,
            'data' => $by !== null ? $payload : ['kind' => $kind],
        ]]);
    }
}

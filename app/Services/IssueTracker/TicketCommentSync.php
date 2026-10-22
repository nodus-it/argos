<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Workspace\TaskService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * New comments on the tickets of open tasks become hints in the task (see
 * TaskService::ticketComment). The first look only remembers where the
 * conversation stands; Argos' own comments (they link the task) are
 * skipped.
 */
class TicketCommentSync
{
    public function __construct(
        private readonly IssueTrackerRegistry $registry,
        private readonly TaskService $tasks,
    ) {}

    /** @return int hints added */
    public function sync(Task $task): int
    {
        $ref = $task->external_ref;
        $binding = $task->ticketBinding();
        if ($ref === null || $binding === null || ! $this->registry->has($binding->kind) || ! in_array($task->status, [TaskStatus::Active, TaskStatus::PrOpen], true)) {
            return 0;
        }

        try {
            $comments = $this->registry->make($binding->kind, $binding)->listComments((string) ($ref['owner'] ?? ''), (string) ($ref['project'] ?? ''), (string) $ref['number']);
        } catch (Throwable $e) {
            Log::channel('argos')->info('Ticket comment sync failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return 0;
        }
        if ($comments === []) {
            return 0;
        }

        $seen = $ref['last_comment_id'] ?? null;
        $last = (string) end($comments)['id'];
        $this->remember($task, $last);
        if ($seen === null) {
            return 0;
        }

        $ids = array_map(fn (array $c): string => (string) $c['id'], $comments);
        $from = array_search((string) $seen, $ids, true);
        $new = $from === false ? [] : array_slice($comments, $from + 1);
        $added = 0;
        foreach ($new as $comment) {
            if (str_contains((string) $comment['body'], $task->url())) {
                continue;
            }
            $this->tasks->ticketComment($task, (string) $comment['author'], (string) $comment['body'], $comment['url'] ?? null);
            $added++;
        }

        return $added;
    }

    private function remember(Task $task, string $commentId): void
    {
        $task->forceFill(['external_ref' => [...($task->external_ref ?? []), 'last_comment_id' => $commentId]])->save();
    }
}

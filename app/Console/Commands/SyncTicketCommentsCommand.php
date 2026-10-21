<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\IssueTracker\TicketCommentSync;
use Illuminate\Console\Command;

/** Picks up new comments on the tickets of open tasks (hints in the chat). */
class SyncTicketCommentsCommand extends Command
{
    protected $signature = 'argos:tasks:sync-ticket-comments';

    protected $description = 'Show new comments on the tickets of open tasks as hints in the task.';

    public function handle(TicketCommentSync $sync): int
    {
        $added = 0;
        Task::query()
            ->whereNotNull('external_ref')
            ->whereNull('archived_at')
            ->whereIn('status', [TaskStatus::Active->value, TaskStatus::PrOpen->value])
            ->each(function (Task $task) use ($sync, &$added): void {
                $added += $sync->sync($task);
            });
        $this->info("{$added} new ticket comment(s).");

        return self::SUCCESS;
    }
}

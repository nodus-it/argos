<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Support\TaskRefs;
use Illuminate\Support\Facades\Gate;

/**
 * A task's ticket: linking or creating one, the brief of a follow-up, and
 * new ticket comments as hints in the chat.
 */
class TaskTicketService
{
    public function __construct(private readonly TaskNoticeService $notices) {}

    /**
     * The first message of a follow-up: what the earlier task was about and
     * where it ended (prefilled, never sent automatically).
     */
    public function followUpBrief(Task $task): string
    {
        $parent = $task->parent;
        if ($parent === null) {
            return '';
        }
        $last = TaskEvent::query()->where('task_id', $parent->id)->where('type', TaskEventType::AssistantMessage->value)->latest('id')->value('payload');

        return trim(implode("\n\n", array_filter([
            __('workspaces.task.follow_up_intro', ['title' => $parent->title, 'branch' => (string) $parent->branch]),
            $parent->pr_url !== null ? __('workspaces.task.follow_up_pr', ['url' => $parent->pr_url, 'state' => (string) $parent->pr_state]) : null,
            is_array($last) && trim((string) ($last['text'] ?? '')) !== '' ? __('workspaces.task.follow_up_last')."\n> ".str_replace("\n", "\n> ", mb_strimwidth(trim((string) $last['text']), 0, 1500, '…')) : null,
        ])));
    }

    /** Link a task to a ticket of the workspace's tracker after the fact. */
    public function linkTicket(Task $task, User $by, string $number): void
    {
        Gate::forUser($by)->authorize('update', $task);
        if ($task->external_ref !== null) {
            throw WorkspaceException::translated('workspaces.errors.ticket_linked');
        }
        $ref = app(WorkspaceIssueService::class)->load($task->workspace, $number);
        $task->forceFill(['external_ref' => $ref])->save();
        $this->notices->notice($task, 'ticket_linked', $by, ['ticket' => (string) TaskRefs::ticketLabel($task)]);
        app(WorkspaceIssueService::class)->comment($task, __('workspaces.issue.run.linked', ['url' => $task->url()]));
    }

    /** Open a ticket in the workspace's tracker for a task that has none. */
    public function createTicket(Task $task, User $by, string $title, string $body): void
    {
        Gate::forUser($by)->authorize('update', $task);
        if ($task->external_ref !== null) {
            throw WorkspaceException::translated('workspaces.errors.ticket_linked');
        }
        $ref = app(WorkspaceIssueService::class)->create($task->workspace, $title, trim($body)."\n\n".__('workspaces.issue.created_footer', ['url' => $task->url()]));
        $task->forceFill(['external_ref' => $ref])->save();
        $this->notices->notice($task, 'ticket_created', $by, ['ticket' => (string) TaskRefs::ticketLabel($task), 'url' => $ref['url']]);
    }

    /**
     * A new comment on the task's ticket: a hint in the chat (never sent to
     * Claude by itself) and a notification for the task's people.
     */
    public function ticketComment(Task $task, string $author, string $body, ?string $url = null): void
    {
        $text = mb_strimwidth(trim($body), 0, 2000, '…');
        // The author is a tracker login, not an Argos user: a system notice that names them.
        $this->notices->systemNotice($task, 'ticket_comment', ['author_name' => $author, 'body' => $text, 'url' => $url]);
        app(WorkspaceNotifier::class)->notify($task->workspace, $task, 'ticket', __('workspaces.notifications.ticket_comment', ['name' => $author]), mb_strimwidth($text, 0, 200, '…'));
    }
}

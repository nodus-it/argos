<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\AutoTaskState;
use App\Enums\TaskOrigin;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Services\Workspace\WorkspaceIssueService;

/**
 * Keeps the issue that started an automatic task informed: started (with the
 * link), the plan when it waits for approval (a 👍 on that comment approves
 * it, see PlanApprovalService), and how the run ended. The pull request
 * comment comes from shipping (WorkspaceIssueService::announcePullRequest).
 * Comment failures are only logged — they never stop a run.
 */
class IssueRunReporter
{
    public function __construct(private readonly WorkspaceIssueService $issues) {}

    public function started(Task $task): void
    {
        if ($this->applies($task)) {
            $this->issues->comment($task, __('workspaces.issue.run.started', ['url' => $task->url()]));
        }
    }

    /** Post the plan; a 👍 on this comment approves it. */
    public function planWaiting(Task $task, string $plan): void
    {
        if (! $this->applies($task)) {
            return;
        }

        $commentId = $this->issues->comment($task, __('workspaces.issue.run.plan', ['plan' => trim($plan), 'url' => $task->url()]));
        if ($commentId !== null) {
            // By binding + issue number: the run can reach this point before
            // the ingest has stored the task on the link.
            $issue = (array) $task->external_ref;
            ExternalIssueLink::query()
                ->where('task_provider_binding_id', $issue['binding_id'] ?? '')
                ->where('external_id', (string) ($issue['number'] ?? ''))
                ->update(['plan_comment_id' => $commentId, 'task_id' => $task->id]);
        }
    }

    public function finished(Task $task, AutoTaskState $state, ?string $detail): void
    {
        if (! $this->applies($task) || $state === AutoTaskState::Done) {
            // Done: the pull request comment already said everything.
            return;
        }

        $this->issues->comment($task, __('workspaces.issue.run.'.$state->value, ['detail' => (string) $detail, 'url' => $task->url()]));
    }

    private function applies(Task $task): bool
    {
        return $task->origin === TaskOrigin::Issue && $task->external_ref !== null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\AutoThreadState;
use App\Enums\WorkspaceOrigin;
use App\Models\ExternalIssueLink;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceIssueService;

/**
 * Keeps the issue that started an automatic run informed: started (with the
 * link), the plan when it waits for approval (a 👍 on that comment approves
 * it, see PlanApprovalService), and how the run ended. The pull request
 * comment comes from shipping (WorkspaceIssueService::announcePullRequest).
 * Comment failures are only logged — they never stop a run.
 */
class IssueRunReporter
{
    public function __construct(private readonly WorkspaceIssueService $issues) {}

    public function started(Workspace $workspace): void
    {
        if ($this->applies($workspace)) {
            $this->issues->comment($workspace, __('workspaces.issue.run.started', ['url' => $workspace->url()]));
        }
    }

    /** Post the plan; a 👍 on this comment approves it. */
    public function planWaiting(Thread $thread, string $plan): void
    {
        $workspace = $thread->workspace;
        if (! $this->applies($workspace)) {
            return;
        }

        $commentId = $this->issues->comment($workspace, __('workspaces.issue.run.plan', ['plan' => trim($plan), 'url' => $workspace->url($thread)]));
        if ($commentId !== null) {
            // By binding + issue number: the run can reach this point before
            // the ingest has stored the workspace on the link.
            $issue = (array) $workspace->external_issue;
            ExternalIssueLink::query()
                ->where('task_provider_binding_id', $issue['binding_id'] ?? '')
                ->where('external_id', (string) ($issue['number'] ?? ''))
                ->update(['plan_comment_id' => $commentId, 'workspace_id' => $workspace->id]);
        }
    }

    public function finished(Thread $thread, AutoThreadState $state, ?string $detail): void
    {
        $workspace = $thread->workspace;
        if (! $this->applies($workspace) || $state === AutoThreadState::Done) {
            // Done: the pull request comment already said everything.
            return;
        }

        $this->issues->comment($workspace, __('workspaces.issue.run.'.$state->value, ['detail' => (string) $detail, 'url' => $workspace->url($thread)]));
    }

    private function applies(Workspace $workspace): bool
    {
        return $workspace->origin === WorkspaceOrigin::Issue && $workspace->external_issue !== null;
    }
}

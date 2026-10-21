<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\AutoTaskState;
use App\Enums\TaskProviderKind;
use App\Enums\TaskStatus;
use App\Jobs\SendTicketWebhookJob;
use App\Models\ConnectedAccount;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Services\Workspace\WorkspaceIssueService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the ticket of a task informed: work started (with the link), the
 * plan when an automatic task waits for approval (a 👍 on that comment
 * approves it, see PlanApprovalService), the pull request, how an automatic
 * run ended, and done or cancelled. Merged tasks close their ticket where
 * the integration says so (GitHub/GitLab do it themselves through
 * `Closes #…`). Own systems get the same events as a signed webhook.
 * Failures are only logged — they never stop a task.
 */
class IssueRunReporter
{
    public function __construct(
        private readonly WorkspaceIssueService $issues,
        private readonly IssueTrackerRegistry $registry,
    ) {}

    public function started(Task $task): void
    {
        if ($this->custom($task, 'started')) {
            return;
        }
        if ($task->external_ref !== null) {
            $this->issues->comment($task, __('workspaces.issue.run.started', ['url' => $task->url()]));
        }
    }

    /** Post the plan; a 👍 on this comment approves it. */
    public function planWaiting(Task $task, string $plan): void
    {
        if ($this->custom($task, 'plan_waiting', ['plan' => trim($plan)]) || $task->external_ref === null) {
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

    /** The pull request exists (the tracker comment comes from WorkspaceIssueService). */
    public function pullRequest(Task $task, string $url): void
    {
        if (! $this->custom($task, 'pull_request', ['pull_request_url' => $url])) {
            $this->issues->announcePullRequest($task, $url);
        }
    }

    /** How an automatic run ended (done is said by the pull request). */
    public function finished(Task $task, AutoTaskState $state, ?string $detail): void
    {
        if ($state === AutoTaskState::Done || $task->external_ref === null) {
            return;
        }
        if (! $this->custom($task, 'run_'.$state->value, ['detail' => (string) $detail])) {
            $this->issues->comment($task, __('workspaces.issue.run.'.$state->value, ['detail' => (string) $detail, 'url' => $task->url()]));
        }
    }

    /**
     * The task is done (merged) or cancelled: say so and, for done, close the
     * ticket when the integration wants it and the tracker does not do it
     * through the pull request.
     */
    public function closed(Task $task): void
    {
        $ref = $task->external_ref;
        if ($ref === null || ! $task->status->isFinished()) {
            return;
        }
        $done = $task->status === TaskStatus::Done;
        if ($this->custom($task, $done ? 'done' : 'cancelled')) {
            return;
        }

        $this->issues->comment($task, __($done ? 'workspaces.issue.run.task_done' : 'workspaces.issue.run.task_cancelled', ['url' => $task->url(), 'pr' => (string) $task->pr_url]));

        $kind = TaskProviderKind::tryFrom((string) ($ref['provider'] ?? ''));
        $binding = $this->binding($task);
        if (! $done || $binding === null || ! $binding->close_on_merge || in_array($kind, [TaskProviderKind::GitHub, TaskProviderKind::GitLab], true)) {
            return;
        }
        try {
            $this->registry->make($binding->kind, $binding)->closeIssue((string) ($ref['owner'] ?? ''), (string) ($ref['project'] ?? ''), (string) $ref['number']);
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Closing the ticket failed', ['task' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    /** The task has a new owner: the ticket's assignee follows where the tracker can. */
    public function ownerChanged(Task $task, User $owner): void
    {
        if ($this->custom($task, 'owner_changed', ['owner' => ['name' => $owner->name, 'email' => $owner->email]])) {
            return;
        }
        $ref = $task->external_ref;
        $binding = $this->binding($task);
        if ($ref === null || $binding === null || ! $this->registry->has($binding->kind)) {
            return;
        }
        $assignee = self::trackerLogin($owner, $binding->kind);
        if ($assignee === null) {
            return;
        }
        try {
            $this->registry->make($binding->kind, $binding)->assignIssue((string) ($ref['owner'] ?? ''), (string) ($ref['project'] ?? ''), (string) $ref['number'], $assignee);
        } catch (Throwable $e) {
            Log::channel('argos')->info('Moving the ticket assignee failed', ['task' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * How a person is known in a tracker: their login of the connected
     * account (GitHub/GitLab), their e-mail (Linear).
     */
    public static function trackerLogin(User $user, TaskProviderKind $kind): ?string
    {
        if ($kind === TaskProviderKind::Linear) {
            return $user->email;
        }
        $provider = $kind->providerKey();
        $account = $provider !== null ? ConnectedAccount::query()->where('user_id', $user->id)->where('provider', $provider)->first() : null;

        return $account?->nickname !== null && $account->nickname !== '' ? $account->nickname : null;
    }

    private function binding(Task $task): ?TaskProviderBinding
    {
        $id = $task->external_ref['binding_id'] ?? null;

        return is_string($id) ? TaskProviderBinding::query()->find($id) : null;
    }

    /**
     * Own systems: queue the signed webhook. True when the task's ticket
     * belongs to one (nothing else to do then).
     *
     * @param  array<string, mixed>  $extra
     */
    private function custom(Task $task, string $event, array $extra = []): bool
    {
        if (($task->external_ref['provider'] ?? null) !== TaskProviderKind::Custom->value) {
            return false;
        }
        $binding = $this->binding($task);
        if ($binding !== null) {
            SendTicketWebhookJob::dispatch($binding->id, [
                'event' => $event,
                'ticket' => ['key' => $task->external_ref['key'] ?? $task->external_ref['number'] ?? null, 'url' => $task->external_ref['url'] ?? null],
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->state()->value,
                    'url' => $task->url(),
                    'branch' => $task->branch,
                    'pull_request' => $task->pr_url === null ? null : ['url' => $task->pr_url, 'number' => $task->pr_number, 'state' => $task->pr_state],
                ],
                ...$extra,
                'sent_at' => now()->toIso8601String(),
            ]);
        }

        return true;
    }
}

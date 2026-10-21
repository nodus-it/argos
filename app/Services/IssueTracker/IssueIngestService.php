<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\Capability;
use App\Enums\TaskOrigin;
use App\Enums\TaskProviderKind;
use App\Models\ConnectedAccount;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\Workspace\AgentPool;
use App\Services\Workspace\AutoRunService;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceIssueService;
use Illuminate\Support\Facades\Log;

final class IssueIngestService
{
    public function __construct(
        private readonly IssueFilterMatcher $filters,
        private readonly IssueSignature $signatures,
        private readonly AutoRunService $runs,
        private readonly WorkspaceIssueService $issues,
        private readonly AgentPool $pool,
        private readonly TaskService $tasks,
    ) {}

    /** The Argos person behind the issue's assignee (connected account login, or e-mail for Linear). */
    private function assigneeUser(ExternalIssue $issue, TaskProviderBinding $binding): ?User
    {
        if ($issue->assignee === null || $issue->assignee === '') {
            return null;
        }
        if ($binding->kind === TaskProviderKind::Linear) {
            return User::query()->where('email', $issue->assignee)->first();
        }
        $provider = $binding->kind->providerKey();

        return $provider === null ? null : ConnectedAccount::query()
            ->where('provider', $provider)
            ->where('nickname', $issue->assignee)
            ->first()?->user;
    }

    /** May own a task in the workspace and has a subscription to run it on. */
    private function mayRunOn(User $user, Workspace $workspace): bool
    {
        return ($workspace->roleOf($user)?->grants(Capability::TaskCreate) ?? false)
            && $this->pool->usableAgentsFor($user)->isNotEmpty();
    }

    /**
     * Process a normalized issue for the given binding.
     *
     * Creates or updates the ExternalIssueLink and, the first time an issue
     * matches the binding's filters, starts an automatic task in the
     * binding's workspace. It belongs to the issue's assignee when they agreed
     * to run automatic tasks on their subscription (profile), otherwise to
     * the workspace's automation user with the assignee invited. Returns the
     * link.
     */
    public function ingest(ExternalIssue $issue, TaskProviderBinding $binding): ExternalIssueLink
    {
        $signature = $this->signatures->for($issue);

        $link = ExternalIssueLink::firstOrNew([
            'task_provider_binding_id' => $binding->id,
            'external_id' => $issue->externalId,
        ]);

        $link->external_url = $issue->url;
        $link->last_synced_at = now();
        $link->signature = $signature;

        // Launch once, ever: a task someone deleted is not re-created on
        // the next poll; an issue first seen not matching (labelled later)
        // still launches.
        if (! $this->filters->passes($issue, $binding) || $link->launched_at !== null) {
            $link->save();

            return $link;
        }

        $workspace = $binding->workspace;
        $assignee = $workspace !== null ? $this->assigneeUser($issue, $binding) : null;
        $actor = $assignee !== null && $assignee->auto_task_consent && $this->mayRunOn($assignee, $workspace)
            ? $assignee
            : $workspace?->automationUser;
        if ($workspace === null || $actor === null) {
            // Nothing to act for — the binding shows why; the issue launches
            // once an automation user is set.
            $binding->forceFill(['last_error' => __('workspaces.issue.run.no_automation_user')])->save();
            $link->save();

            return $link;
        }

        // Saved first: the run may report back (plan comment) before launch() returns.
        $link->save();
        $data = $this->issues->fromExternal($issue, $binding);
        try {
            $task = $this->runs->launch($actor, $workspace, $this->issues->promptFor($data), ['issue' => $data], [], TaskOrigin::Issue);
        } catch (WorkspaceException $e) {
            // No agent, capacity reached …: retried on the next poll.
            Log::channel('argos')->info('Issue run could not start', ['binding' => $binding->id, 'issue' => $issue->externalId, 'error' => $e->getMessage()]);
            $link->save();

            return $link;
        }

        if ($assignee !== null && $assignee->id !== $actor->id && $workspace->roleOf($assignee) !== null) {
            rescue(fn () => $this->tasks->invite($task, $actor, $assignee), report: false);
        }

        $link->task_id = $task->id;
        $link->launched_at = now();
        $link->save();

        return $link;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\TaskOrigin;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\Workspace\AutoRunService;
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
    ) {}

    /**
     * Process a normalized issue for the given binding.
     *
     * Creates or updates the ExternalIssueLink and, the first time an issue
     * matches the binding's filters, starts an automatic run in a new
     * workspace for the project's automation user. Returns the link.
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
        $actor = $workspace?->automationUser;
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

        $link->task_id = $task->id;
        $link->launched_at = now();
        $link->save();

        return $link;
    }
}

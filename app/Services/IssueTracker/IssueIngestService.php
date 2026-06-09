<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\Task\TaskService;

final class IssueIngestService
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly IssueFilterMatcher $filters,
        private readonly IssueSignature $signatures,
    ) {}

    /**
     * Process a raw issue payload for the given binding.
     *
     * Creates or updates the corresponding ExternalIssueLink, and optionally
     * creates a new Task when a matching issue is seen for the first time.
     * Returns the ExternalIssueLink (new or updated).
     *
     * @param  array<string, mixed>  $issue  Raw issue data from the provider API
     */
    public function ingest(array $issue, TaskProviderBinding $binding): ExternalIssueLink
    {
        $externalId = $this->resolveExternalId($issue, $binding->kind);

        if (! $this->filters->passes($issue, $binding)) {
            $link = ExternalIssueLink::firstOrNew([
                'task_provider_binding_id' => $binding->id,
                'external_id' => $externalId,
            ]);

            $link->external_url = (string) ($issue['html_url'] ?? $issue['web_url'] ?? '');
            $link->last_synced_at = now();
            $link->signature = $this->signatures->for($issue);
            $link->save();

            return $link;
        }

        $signature = $this->signatures->for($issue);

        $link = ExternalIssueLink::firstOrNew([
            'task_provider_binding_id' => $binding->id,
            'external_id' => $externalId,
        ]);

        $link->external_url = (string) ($issue['html_url'] ?? $issue['web_url'] ?? '');
        $link->last_synced_at = now();
        $link->signature = $signature;

        // Import once, ever. task_imported_at (not task_id) is the gate: an
        // issue first seen NOT matching, then labelled later, still imports;
        // but a task the user deleted (task_id nulled, marker kept) is never
        // silently re-imported on the next poll.
        if ($link->task_imported_at === null) {
            $task = $this->createTaskFromIssue($issue, $binding);
            $link->task_id = $task->id;
            $link->task_imported_at = now();
        }

        $link->save();

        return $link;
    }

    /**
     * The per-repo identifier the issue is addressed by for API operations
     * (comments, fetches) — NOT the provider's global id. GitHub uses `number`,
     * GitLab `iid`, Linear the GraphQL node `id`. Using the global id made
     * write-back POST to a non-existent issue (HTTP 404).
     *
     * @param  array<string, mixed>  $issue
     */
    private function resolveExternalId(array $issue, TaskProviderKind $kind): string
    {
        $id = match ($kind) {
            TaskProviderKind::GitHub => $issue['number'] ?? $issue['id'] ?? null,
            TaskProviderKind::GitLab => $issue['iid'] ?? $issue['id'] ?? null,
            default => $issue['id'] ?? null,
        };

        return (string) ($id ?? '');
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function createTaskFromIssue(array $issue, TaskProviderBinding $binding): Task
    {
        $title = (string) ($issue['title'] ?? 'Imported issue');
        $body = (string) ($issue['body'] ?? $issue['description'] ?? '');

        $profile = $binding->repoProfile;
        $autoConcept = $profile?->auto_concept ?? false;

        return $this->taskService->createTask([
            'name' => $title,
            'description' => $body !== '' ? $body : $title,
            'repo_profile_id' => $binding->repo_profile_id,
            'auto_concept' => $autoConcept,
        ]);
    }
}

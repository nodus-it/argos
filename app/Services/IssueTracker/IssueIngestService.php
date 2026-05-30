<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\Task\TaskService;
use Illuminate\Support\Arr;

final class IssueIngestService
{
    public function __construct(private readonly TaskService $taskService) {}

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

        if (! $this->passesFilters($issue, $binding)) {
            $link = ExternalIssueLink::firstOrNew([
                'task_provider_binding_id' => $binding->id,
                'external_id' => $externalId,
            ]);

            $link->external_url = (string) ($issue['html_url'] ?? $issue['web_url'] ?? '');
            $link->last_synced_at = now();
            $link->signature = $this->computeSignature($issue);
            $link->save();

            return $link;
        }

        $signature = $this->computeSignature($issue);

        $link = ExternalIssueLink::firstOrNew([
            'task_provider_binding_id' => $binding->id,
            'external_id' => $externalId,
        ]);

        $isNew = ! $link->exists;

        $link->external_url = (string) ($issue['html_url'] ?? $issue['web_url'] ?? '');
        $link->last_synced_at = now();
        $link->signature = $signature;

        if ($isNew && $link->task_id === null) {
            $task = $this->createTaskFromIssue($issue, $binding);
            $link->task_id = $task->id;
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
    private function passesFilters(array $issue, TaskProviderBinding $binding): bool
    {
        $filters = $binding->filters ?? [];

        $requiredState = Arr::get($filters, 'state');
        if ($requiredState !== null && $requiredState !== '') {
            $issueState = (string) ($issue['state'] ?? $issue['status'] ?? '');
            if ($issueState !== $requiredState) {
                return false;
            }
        }

        $requiredLabels = Arr::get($filters, 'labels');
        if (is_array($requiredLabels) && count($requiredLabels) > 0) {
            // OR semantics: the issue must carry at least one of the configured
            // labels (matches the documented behaviour in SETUP-TASK-PROVIDERS.md).
            $issueLabels = $this->extractLabels($issue);
            if (count(array_intersect($requiredLabels, $issueLabels)) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return list<string>
     */
    private function extractLabels(array $issue): array
    {
        $raw = $issue['labels'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return array_map(
            fn (mixed $l): string => is_array($l) ? (string) ($l['name'] ?? '') : (string) $l,
            $raw,
        );
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

    /**
     * @param  array<string, mixed>  $issue
     */
    private function computeSignature(array $issue): string
    {
        return hash('sha256', serialize([
            $issue['title'] ?? '',
            $issue['body'] ?? $issue['description'] ?? '',
            $issue['state'] ?? $issue['status'] ?? '',
            $issue['labels'] ?? [],
        ]));
    }
}

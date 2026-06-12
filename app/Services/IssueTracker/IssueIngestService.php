<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Events\Integration\NewIssueFoundEvent;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\Task\TaskService;
use Illuminate\Support\Facades\Event;

final class IssueIngestService
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly IssueFilterMatcher $filters,
        private readonly IssueSignature $signatures,
    ) {}

    /**
     * Process a normalized issue for the given binding.
     *
     * Creates or updates the corresponding ExternalIssueLink, and optionally
     * creates a new Task when a matching issue is seen for the first time.
     * Returns the ExternalIssueLink (new or updated).
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

        if (! $this->filters->passes($issue, $binding)) {
            $link->save();

            return $link;
        }

        // Import once, ever. task_imported_at (not task_id) is the gate: an
        // issue first seen NOT matching, then labelled later, still imports;
        // but a task the user deleted (task_id nulled, marker kept) is never
        // silently re-imported on the next poll.
        $importedTask = null;
        if ($link->task_imported_at === null) {
            $importedTask = $this->createTaskFromIssue($issue, $binding);
            $link->task_id = $importedTask->id;
            $link->task_imported_at = now();
        }

        $link->save();

        // Fan-out/audit seam — fired only on the first import, after the link is
        // persisted, so the core ingest stays idempotent regardless of listeners.
        if ($importedTask !== null) {
            Event::dispatch(new NewIssueFoundEvent($binding, $issue, $importedTask));
        }

        return $link;
    }

    private function createTaskFromIssue(ExternalIssue $issue, TaskProviderBinding $binding): Task
    {
        $title = $issue->title !== '' ? $issue->title : 'Imported issue';
        $body = $issue->body !== '' ? $issue->body : $title;

        $profile = $binding->repoProfile;
        $autoConcept = $profile?->auto_concept ?? false;

        return $this->taskService->createTask([
            'name' => $title,
            'description' => $body,
            'repo_profile_id' => $binding->repo_profile_id,
            'auto_concept' => $autoConcept,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Models\ExternalIssueLink;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Outbound status-sync: when an Argos task is marked completed, close/resolve
 * the source issue it was imported from. Opt-in per binding via the
 * `close_on_complete` filter flag so it never surprises a user who only wants
 * inbound ingest. Best-effort — a provider failure must not break completing
 * the task in Argos.
 */
class IssueStatusSync
{
    public function __construct(private readonly IssueTrackerRegistry $registry) {}

    /**
     * Close the source issue for a completed task, if it was imported from a
     * binding that opted into status-sync.
     */
    public function closeSourceIssue(Task $task): void
    {
        $link = ExternalIssueLink::query()
            ->where('task_id', $task->id)
            ->with('binding.connectedAccount')
            ->first();

        if (! $link instanceof ExternalIssueLink) {
            return;
        }

        $binding = $link->binding;

        if ($binding === null || ($binding->filters['close_on_complete'] ?? false) !== true) {
            return;
        }

        if (! $this->registry->has($binding->kind)) {
            return;
        }

        try {
            $tracker = $this->registry->make($binding->kind, $binding);
            [$owner, $project] = $this->parseRef($binding->external_project_ref ?? '');

            // Post a closing note with the PR link before flipping the state, so
            // the issue thread ends with the deliverable. Best-effort: a comment
            // failure must not stop the close itself.
            if (($task->pr_url ?? '') !== '') {
                try {
                    $tracker->createComment(
                        $owner,
                        $project,
                        $link->external_id,
                        "**Argos** — Task abgeschlossen, Issue wird geschlossen. PR: {$task->pr_url}",
                    );
                } catch (\Throwable) {
                    // ignore — close is the important part
                }
            }

            $tracker->closeIssue($owner, $project, $link->external_id);
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('IssueStatusSync: failed to close source issue', [
                'task_id' => $task->id,
                'binding_id' => $binding->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Split an external_project_ref into [owner, project]. Mirrors the notifier:
     * "owner/repo" → ['owner', 'repo']; a Linear team key "ENG" → ['ENG', ''].
     *
     * @return array{0: string, 1: string}
     */
    private function parseRef(string $ref): array
    {
        $parts = explode('/', $ref, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}

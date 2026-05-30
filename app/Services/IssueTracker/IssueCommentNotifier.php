<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\PhaseStatus;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

final class IssueCommentNotifier
{
    public function __construct(private readonly IssueTrackerRegistry $registry) {}

    /**
     * Post a phase-completion comment back to the external issue, if any.
     *
     * This is a No-Op when the task has no ExternalIssueLink. Errors are
     * caught and logged so the workflow never stalls because of a comment failure.
     */
    public function notifyPhaseCompletion(Task $task, string $phase, string $status): void
    {
        $link = ExternalIssueLink::query()
            ->where('task_id', $task->id)
            ->with('binding.connectedAccount')
            ->first();

        if (! $link instanceof ExternalIssueLink) {
            return;
        }

        $binding = $link->binding;

        if (! $this->registry->has($binding->kind)) {
            return;
        }

        try {
            $tracker = $this->registry->make($binding->kind, $binding);

            [$owner, $project] = $this->parseRef($binding->external_project_ref ?? '');
            $issueNumber = $link->external_id;
            $body = $this->formatComment($task, $phase, $status);

            $tracker->createComment($owner, $project, $issueNumber, $body);
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('IssueCommentNotifier: failed to post comment', [
                'task_id' => $task->id,
                'phase' => $phase,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatComment(Task $task, string $phase, string $status): string
    {
        $phaseLabel = ucfirst($phase);
        $statusLabel = ucfirst($status);

        // Build the link via the named route, NOT TaskResource::getUrl():
        // the notifier runs inside the queue worker, where no Filament panel is
        // current, and getUrl() throws "No default Filament panel is set" there.
        $taskUrl = route('filament.admin.resources.tasks.view', ['record' => $task->getKey()]);

        $header = "**Argos** — Phase **{$phaseLabel}** abgeschlossen mit Status: **{$statusLabel}**";
        $link = "[Task in Argos öffnen]({$taskUrl})";

        $concept = $this->conceptBody($task, $phase, $status);
        if ($concept !== null) {
            return $header."\n\n---\n\n".$concept."\n\n---\n\n".$link;
        }

        return $header."\n\n".$link;
    }

    /**
     * The concept document to inline when the concept phase completed, so it can
     * be reviewed on the issue itself. Read fresh from the DB (the task instance
     * handed in from the queue job may predate the worker writing concept_md),
     * and capped to stay well under the providers' comment-size limits. Returns
     * null for any other phase/status or when there is no concept.
     */
    private function conceptBody(Task $task, string $phase, string $status): ?string
    {
        if ($phase !== 'concept' || $status !== PhaseStatus::Completed->value) {
            return null;
        }

        $markdown = $task->fresh()?->concept_md;
        if (! is_string($markdown) || trim($markdown) === '') {
            return null;
        }

        $max = 60000;
        if (mb_strlen($markdown) > $max) {
            return mb_substr($markdown, 0, $max)."\n\n_… gekürzt — vollständiges Konzept in Argos._";
        }

        return $markdown;
    }

    /**
     * @return array{string, string}
     */
    private function parseRef(string $ref): array
    {
        $parts = explode('/', $ref, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}

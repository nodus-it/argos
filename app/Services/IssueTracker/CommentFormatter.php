<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\PhaseStatus;
use App\Models\Task;

/**
 * Builds the markdown body Argos posts back to an external issue on phase
 * completion: a header, the per-phase content (capped under provider limits)
 * and a link back into Argos.
 */
class CommentFormatter
{
    private const MAX_BODY = 60000;

    public function format(Task $task, string $phase, string $status): string
    {
        $phaseLabel = ucfirst($phase);
        $statusLabel = ucfirst($status);

        // Build the link via the named route, NOT TaskResource::getUrl():
        // the notifier runs inside the queue worker, where no Filament panel is
        // current, and getUrl() throws "No default Filament panel is set" there.
        $taskUrl = route('filament.admin.resources.tasks.view', ['record' => $task->getKey()]);

        $header = "**Argos** — Phase **{$phaseLabel}** abgeschlossen mit Status: **{$statusLabel}**";
        $link = "[Task in Argos öffnen]({$taskUrl})";

        $extra = $this->phaseExtra($task, $phase, $status);
        if ($extra !== null) {
            return $header."\n\n---\n\n".$extra."\n\n---\n\n".$link;
        }

        return $header."\n\n".$link;
    }

    /**
     * Per-phase content to inline in the comment on success, read fresh from the
     * DB (the queue job's task instance may predate the worker writing these):
     *  - concept:   the full concept for review on the issue;
     *  - implement: the result summaries;
     *  - push:      the pull/merge request link.
     * Null for any other phase/status or when the content is missing.
     */
    private function phaseExtra(Task $task, string $phase, string $status): ?string
    {
        if ($status !== PhaseStatus::Completed->value) {
            return null;
        }

        $task = $task->fresh();
        if ($task === null) {
            return null;
        }

        return match ($phase) {
            'concept' => $this->cap($task->concept_md),
            'implement' => $this->implementResult($task),
            'push' => $this->pullRequestLink($task),
            default => null,
        };
    }

    private function implementResult(Task $task): ?string
    {
        $sections = [];

        $nonTechnical = trim((string) $task->implement_summary_nontechnical);
        if ($nonTechnical !== '') {
            $sections[] = "**Ergebnis**\n\n".$nonTechnical;
        }

        $technical = trim((string) $task->implement_summary_technical);
        if ($technical !== '') {
            $sections[] = "**Technische Details**\n\n".$technical;
        }

        return $sections === [] ? null : $this->cap(implode("\n\n", $sections));
    }

    private function pullRequestLink(Task $task): ?string
    {
        $prUrl = trim((string) $task->pr_url);

        return $prUrl === '' ? null : "**Pull Request:** {$prUrl}";
    }

    /**
     * Cap a body well under the providers' comment-size limits, with a note +
     * the Argos link (already appended by the caller) for the full content.
     */
    private function cap(?string $markdown): ?string
    {
        if (! is_string($markdown) || trim($markdown) === '') {
            return null;
        }

        if (mb_strlen($markdown) > self::MAX_BODY) {
            return mb_substr($markdown, 0, self::MAX_BODY)."\n\n_… gekürzt — vollständig in Argos._";
        }

        return $markdown;
    }
}

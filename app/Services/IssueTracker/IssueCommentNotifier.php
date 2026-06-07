<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\PhaseStatus;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Services\IssueTracker\Concerns\ParsesExternalProjectRef;
use Illuminate\Support\Facades\Log;

final class IssueCommentNotifier
{
    use ParsesExternalProjectRef;

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
            ->with(['binding.connectedAccount', 'binding.providerCredential'])
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

            $result = $tracker->createComment($owner, $project, $issueNumber, $body);

            // Remember the concept comment's id so a 👍 on it can later be
            // polled to approve and start implement.
            if ($phase === 'concept' && $status === PhaseStatus::Completed->value) {
                $commentId = $tracker->commentId($result);
                if ($commentId !== null) {
                    $link->concept_comment_id = $commentId;
                    $link->save();
                }
            }
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

        $max = 60000;
        if (mb_strlen($markdown) > $max) {
            return mb_substr($markdown, 0, $max)."\n\n_… gekürzt — vollständig in Argos._";
        }

        return $markdown;
    }

    /**
     * @return array{string, string}
     */
}

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

    public function __construct(
        private readonly IssueTrackerRegistry $registry,
        private readonly CommentFormatter $formatter,
    ) {}

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
            $body = $this->formatter->format($task, $phase, $status);

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
}

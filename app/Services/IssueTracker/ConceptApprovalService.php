<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\Phase;
use App\Enums\WorkflowStatus;
use App\Models\ExternalIssueLink;
use App\Services\IssueTracker\Concerns\ParsesExternalProjectRef;
use App\Services\Task\TaskService;
use Illuminate\Support\Facades\Log;

/**
 * Starts the implement phase when an authorized person reacts with 👍 on the
 * concept comment Argos posted on the external issue. Polled (providers do not
 * push reaction events), gated on repo write/admin access so not just anyone
 * can trigger it, and idempotent: startPhase() moves the task out of
 * ConceptReview, so a second reaction is a no-op.
 */
final class ConceptApprovalService
{
    use ParsesExternalProjectRef;

    /** Provider-specific identifiers for the 👍 reaction. */
    private const THUMBS_UP = ['+1', 'thumbsup', '👍'];

    public function __construct(
        private readonly IssueTrackerRegistry $registry,
        private readonly TaskService $taskService,
    ) {}

    /**
     * Returns true when an authorized 👍 was found and implement was started.
     */
    public function check(ExternalIssueLink $link): bool
    {
        $task = $link->task;
        $binding = $link->binding;
        $commentId = $link->concept_comment_id;

        if ($task === null || $commentId === null || $commentId === '') {
            return false;
        }

        if ($task->workflow_status !== WorkflowStatus::ConceptReview) {
            return false;
        }

        if (! $this->registry->has($binding->kind)) {
            return false;
        }

        try {
            $tracker = $this->registry->make($binding->kind, $binding);
            [$owner, $project] = $this->parseRef($binding->external_project_ref ?? '');

            $reactions = $tracker->getCommentReactions($owner, $project, $link->external_id, $commentId);

            foreach ($reactions as $reaction) {
                if (! in_array(mb_strtolower($reaction['emoji']), self::THUMBS_UP, true)) {
                    continue;
                }

                if (! $tracker->userCanApprove($owner, $project, $reaction)) {
                    continue;
                }

                $this->taskService->startPhase($task, Phase::Implement);

                Log::channel('argos')->info('Concept approved via reaction — implement started', [
                    'task_id' => $task->id,
                    'approver' => $reaction['user_login'],
                    'kind' => $binding->kind->value,
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('ConceptApprovalService: check failed', [
                'link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * @return array{string, string}
     */
}

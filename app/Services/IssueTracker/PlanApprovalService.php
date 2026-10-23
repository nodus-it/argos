<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\AutoTaskState;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Services\IssueTracker\Concerns\ParsesExternalProjectRef;
use App\Services\Workspace\AutoTaskDriver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Approves the plan of an automatic run when an authorized person reacts
 * with 👍 on the plan comment Argos posted on the issue. Polled (providers
 * do not push reaction events), gated on write access (userCanApprove) and
 * idempotent: once approved the run leaves awaiting_plan_approval.
 */
final class PlanApprovalService
{
    use ParsesExternalProjectRef;

    /** Provider-specific identifiers for the 👍 reaction. */
    private const THUMBS_UP = ['+1', 'thumbsup', '👍'];

    public function __construct(
        private readonly IssueTrackerRegistry $registry,
        private readonly AutoTaskDriver $driver,
    ) {}

    /** True when an authorized 👍 was found and the plan approved. */
    public function check(ExternalIssueLink $link): bool
    {
        $binding = $link->binding;
        $task = $link->task_id === null ? null : Task::query()
            ->with(['workspace', 'owner'])
            ->whereKey($link->task_id)
            ->where('auto_state', AutoTaskState::AwaitingPlanApproval->value)
            ->first();
        if ($task === null || $task->owner === null || $link->plan_comment_id === null || ! $this->registry->has($binding->kind)) {
            return false;
        }

        try {
            $tracker = $this->registry->make($binding->kind, $binding);
            [$owner, $project] = $this->parseRef($binding->external_project_ref ?? '');

            foreach ($tracker->getCommentReactions($owner, $project, $link->external_id, $link->plan_comment_id) as $reaction) {
                if (! in_array(mb_strtolower($reaction['emoji']), self::THUMBS_UP, true) || ! $tracker->userCanApprove($owner, $project, $reaction)) {
                    continue;
                }

                // Acts for the run's owner; the approver is logged.
                $this->driver->decidePlan($task, $task->owner, true);
                Log::channel('argos')->info('Plan approved via issue reaction', ['task' => $task->id, 'approver' => $reaction['user_login']]);

                return true;
            }
        } catch (Throwable $e) {
            Log::channel('argos')->warning('PlanApprovalService: check failed', ['link_id' => $link->id, 'error' => $e->getMessage()]);
        }

        return false;
    }
}

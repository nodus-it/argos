<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\GitProvider\PullRequestDetails;
use App\Services\IssueTracker\IssueRunReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A task's pull/merge request as the provider sees it: state (draft, open,
 * merged, closed), checks/pipeline, reviews, conflicts and auto-merge.
 * Refreshing stores the summary on the task, tells its people what changed
 * (red pipeline, changes requested, merged) and finishes or cancels the task
 * when the pull request is merged or closed. The actions — ready for
 * review, auto-merge, hand the pipeline log, the review or a rebase to
 * Claude — act for the person who ships.
 */
class PullRequestService
{
    /** Details (reviews, comments) are cached this long for the sidebar. */
    private const DETAILS_SECONDS = 60;

    public function __construct(
        private readonly GitServiceFactory $git,
        private readonly TaskService $tasks,
        private readonly WorkspaceNotifier $notifier,
    ) {}

    /**
     * Fetch and store the pull request's state; failures keep the old one.
     * Returns the details, or null without a pull request or on failure.
     */
    public function refresh(Task $task): ?PullRequestDetails
    {
        if ($task->pr_number === null) {
            return null;
        }

        $workspace = $task->workspace;
        [$owner, $repo] = $workspace->ownerAndRepo();
        try {
            $details = $this->git->forWorkspace($workspace)->pullRequestDetails($owner, $repo, $task->pr_number);
        } catch (Throwable $e) {
            Log::channel('argos')->info('Pull request sync failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return null;
        }
        Cache::put($this->cacheKey($task), $details, self::DETAILS_SECONDS);

        $before = ['checks' => $task->pr_checks['state'] ?? null, 'review' => $task->pr_review_state, 'state' => $task->pr_state];
        $task->forceFill([
            'pr_state' => $details->state,
            'pr_url' => $details->url ?? $task->pr_url,
            'pr_checks' => ['state' => $details->checks['state'], 'url' => $details->checks['url'], 'failed' => $details->checks['failed']],
            'pr_review_state' => $details->reviewState,
            'pr_conflicts' => $details->conflicts,
            'auto_merge' => $details->autoMerge,
            'pr_synced_at' => now(),
        ]);

        $finished = ! $task->status->isFinished() && in_array($details->state, ['merged', 'closed'], true);
        if ($finished) {
            $task->forceFill(['status' => $details->state === 'merged' ? TaskStatus::Done : TaskStatus::Cancelled, 'done_at' => now()]);
        } elseif (! $task->status->isFinished() && $task->status !== TaskStatus::Preparing) {
            $task->status = TaskStatus::PrOpen;
        }
        $task->save();

        $this->announceChanges($task, $before, $details);
        if ($finished) {
            app(IssueRunReporter::class)->closed($task);
            app(WorkspaceSetupService::class)->pullRequestFinished($task);
            $this->tasks->endPreview($task);
        }

        return $details;
    }

    /** Cached details for the sidebar (refreshing when there are none). */
    public function details(Task $task): ?PullRequestDetails
    {
        $cached = Cache::get($this->cacheKey($task));

        return $cached instanceof PullRequestDetails ? $cached : $this->refresh($task);
    }

    /** Take the draft out of draft state. */
    public function markReady(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('ship', $task);
        $this->provider($task, fn ($git, string $owner, string $repo) => $git->markPullRequestReady($owner, $repo, (int) $task->pr_number));
        $this->tasks->notice($task, 'pr_ready', $by);
        $this->refresh($task);
    }

    /**
     * Merge on its own once the pipeline is green and the reviews allow it
     * (the provider's native auto-merge).
     */
    public function setAutoMerge(Task $task, User $by, bool $on): void
    {
        Gate::forUser($by)->authorize('ship', $task);
        if ($task->pr_number === null) {
            // No pull request yet: remembered and switched on when shipping opens it.
            $task->forceFill(['auto_merge' => $on])->save();

            return;
        }

        $done = $this->provider($task, fn ($git, string $owner, string $repo) => $on
            ? $git->enableAutoMerge($owner, $repo, (int) $task->pr_number)
            : $git->disableAutoMerge($owner, $repo, (int) $task->pr_number));
        if ($done !== true) {
            throw WorkspaceException::translated($on ? 'workspaces.pr.auto_merge_unavailable' : 'workspaces.pr.auto_merge_failed');
        }
        $task->forceFill(['auto_merge' => $on])->save();
        $this->tasks->notice($task, $on ? 'auto_merge_on' : 'auto_merge_off', $by);
        Cache::forget($this->cacheKey($task));
    }

    /** A freshly opened pull request of a task that wants auto-merge gets it. */
    public function applyAutoMerge(Task $task): void
    {
        if (! $task->auto_merge || $task->pr_number === null) {
            return;
        }
        try {
            $done = $this->provider($task, fn ($git, string $owner, string $repo) => $git->enableAutoMerge($owner, $repo, (int) $task->pr_number));
        } catch (WorkspaceException) {
            $done = false;
        }
        if ($done !== true) {
            $task->forceFill(['auto_merge' => false])->save();
            $this->tasks->systemNotice($task, 'auto_merge_unavailable');
        }
    }

    /** Hand the failing pipeline's log to Claude. */
    public function fixChecks(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('prompt', $task);
        $log = (string) $this->provider($task, fn ($git, string $owner, string $repo) => $git->failedCheckLog($owner, $repo, (int) $task->pr_number));
        if (trim($log) === '') {
            throw WorkspaceException::translated('workspaces.pr.no_failed_checks');
        }

        $this->tasks->send($task, $by, __('workspaces.pr.fix_prompt', ['url' => (string) ($task->pr_checks['url'] ?? $task->pr_url)])."\n\n```\n".mb_strcut($log, -20_000)."\n```");
    }

    /** Hand the reviews and review comments to Claude. */
    public function sendReview(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('prompt', $task);
        $details = $this->details($task);
        if ($details === null || ($details->reviews === [] && $details->comments === [])) {
            throw WorkspaceException::translated('workspaces.pr.no_review');
        }

        $lines = [__('workspaces.pr.review_prompt', ['url' => (string) $task->pr_url])];
        foreach ($details->reviews as $review) {
            if (trim($review['body']) !== '') {
                $lines[] = "- **{$review['author']}** ({$review['state']}): ".trim($review['body']);
            }
        }
        foreach ($details->comments as $comment) {
            $where = $comment['path'] !== null ? ' `'.$comment['path'].($comment['line'] !== null ? ':'.$comment['line'] : '').'`' : '';
            $lines[] = "- **{$comment['author']}**{$where}: ".trim($comment['body']);
        }

        $this->tasks->send($task, $by, implode("\n", $lines));
    }

    /** The base moved on or there are conflicts: Claude rebases the branch. */
    public function rebase(Task $task, User $by): void
    {
        $this->tasks->send($task, $by, __('workspaces.ship.rebase_prompt', ['branch' => $task->base_branch]));
    }

    /**
     * @param  array{checks: string|null, review: string|null, state: string|null}  $before
     */
    private function announceChanges(Task $task, array $before, PullRequestDetails $details): void
    {
        $changes = [
            'pr_checks_failed' => $details->checks['state'] === 'failure' && $before['checks'] !== 'failure',
            'pr_checks_passed' => $details->checks['state'] === 'success' && $before['checks'] === 'failure',
            'pr_changes_requested' => $details->reviewState === 'changes_requested' && $before['review'] !== 'changes_requested',
            'pr_approved' => $details->reviewState === 'approved' && $before['review'] !== 'approved',
            'pr_merged' => $details->state === 'merged' && $before['state'] !== 'merged',
            'pr_closed' => $details->state === 'closed' && $before['state'] !== 'closed',
        ];
        foreach (array_keys(array_filter($changes)) as $kind) {
            $this->tasks->systemNotice($task, $kind, ['url' => $kind === 'pr_checks_failed' ? $details->checks['url'] : $details->url]);
            $this->notifier->notify($task->workspace, $task, 'ship', __('workspaces.pr.notifications.'.$kind), $task->title);
        }
    }

    /**
     * Run a provider call for the task's pull request.
     *
     * @param  callable(mixed, string, string): mixed  $call
     */
    private function provider(Task $task, callable $call): mixed
    {
        if ($task->pr_number === null) {
            throw WorkspaceException::translated('workspaces.pr.none');
        }
        [$owner, $repo] = $task->workspace->ownerAndRepo();
        try {
            return $call($this->git->forWorkspace($task->workspace), $owner, $repo);
        } catch (WorkspaceException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::channel('argos')->info('Pull request action failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            throw WorkspaceException::translated('workspaces.pr.failed', ['message' => $e->getMessage()]);
        }
    }

    private function cacheKey(Task $task): string
    {
        return 'task-pr:'.$task->id;
    }
}

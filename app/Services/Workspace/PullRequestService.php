<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskStatus;
use App\Jobs\Workspace\RefreshPullRequestJob;
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
    /** Details younger than this are fresh; older ones are shown and refreshed in the background. */
    private const FRESH_SECONDS = 60;

    /** How long details are kept at all (the scheduled sync renews them). */
    private const KEEP_SECONDS = 3600;

    public function __construct(
        private readonly GitServiceFactory $git,
        private readonly TaskService $tasks,
        private readonly TaskNoticeService $notices,
        private readonly WorkspaceNotifier $notifier,
        private readonly TaskStreamPublisher $stream,
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
        app(PullRequestFeedbackService::class)->forget($task);

        $workspace = $task->workspace;
        [$owner, $repo] = $workspace->ownerAndRepo();
        try {
            $details = self::withoutOwnNotes($this->git->forWorkspace($workspace)->pullRequestDetails($owner, $repo, $task->pr_number));
        } catch (Throwable $e) {
            Log::channel('argos')->info('Pull request sync failed', ['task' => $task->id, 'error' => $e->getMessage()]);

            return null;
        }
        Cache::put($this->cacheKey($task), ['at' => time(), 'details' => $details->toArray()], self::KEEP_SECONDS);
        Cache::forget('task-pr-refreshing:'.$task->id);

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
        $changed = $task->isDirty(['pr_state', 'pr_url', 'pr_checks', 'pr_review_state', 'pr_conflicts', 'auto_merge', 'status']);
        $task->save();

        // Open pages re-render the sidebar; most changes come without a chat notice.
        if ($changed) {
            $this->stream->publish($task->workspace_id, $task->id, [['type' => 'pr.updated', 'data' => []]]);
        }
        $this->announceChanges($task, $before, $details);
        if ($finished) {
            app(IssueRunReporter::class)->closed($task);
            app(WorkspaceSetupService::class)->pullRequestFinished($task);
            $this->tasks->endPreview($task);
        }

        return $details;
    }

    /**
     * Cached details. Stale ones come back right away and are refreshed in
     * the background; without any, `$wait` fetches them now, otherwise the
     * background refresh fills them in (the sidebar shows the stored summary
     * meanwhile and polls).
     */
    /** Argos' own shipping notes are no review: "commented" from them alone means none yet. */
    public static function withoutOwnNotes(PullRequestDetails $details): PullRequestDetails
    {
        $others = array_filter($details->comments, fn (array $c): bool => ! PullRequestFeedbackService::fromArgos((string) ($c['body'] ?? '')));
        if ($details->reviewState !== 'commented' || $others !== []) {
            return $details;
        }

        return new PullRequestDetails(
            state: $details->state,
            url: $details->url,
            headSha: $details->headSha,
            conflicts: $details->conflicts,
            behindBase: $details->behindBase,
            checks: $details->checks,
            reviewState: null,
            reviews: $details->reviews,
            comments: $details->comments,
            autoMerge: $details->autoMerge,
        );
    }

    public function details(Task $task, bool $wait = false): ?PullRequestDetails
    {
        if ($task->pr_number === null) {
            return null;
        }
        $cached = Cache::get($this->cacheKey($task));
        if (is_array($cached) && is_array($cached['details'] ?? null)) {
            if (time() - (int) ($cached['at'] ?? 0) > self::FRESH_SECONDS) {
                $this->refreshLater($task);
            }

            return PullRequestDetails::fromArray($cached['details']);
        }
        if ($wait) {
            return $this->refresh($task);
        }
        $this->refreshLater($task);
        // A synchronous queue (tests, small setups) has the details by now.
        $cached = Cache::get($this->cacheKey($task));

        return is_array($cached) && is_array($cached['details'] ?? null) ? PullRequestDetails::fromArray($cached['details']) : null;
    }

    /** Whether details are loaded (else the sidebar polls until they are). */
    public function hasDetails(Task $task): bool
    {
        return is_array(Cache::get($this->cacheKey($task)));
    }

    /** One background refresh at a time per task. */
    private function refreshLater(Task $task): void
    {
        if (Cache::add('task-pr-refreshing:'.$task->id, true, 30)) {
            RefreshPullRequestJob::dispatch($task->id);
        }
    }

    /** Take the draft out of draft state. */
    public function markReady(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('ship', $task);
        $this->provider($task, fn ($git, string $owner, string $repo) => $git->markPullRequestReady($owner, $repo, (int) $task->pr_number));
        $this->notices->notice($task, 'pr_ready', $by);
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
        $this->notices->notice($task, $on ? 'auto_merge_on' : 'auto_merge_off', $by);
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
            $this->notices->systemNotice($task, 'auto_merge_unavailable');
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

    /**
     * @return array<int, string> line number => text
     */
    /** Marks comments Argos writes on pull requests, so they are not read back as review. */
    public const ARGOS_MARKER = '<!-- argos -->';

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
            $this->notices->systemNotice($task, $kind, ['url' => $kind === 'pr_checks_failed' ? $details->checks['url'] : $details->url]);
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

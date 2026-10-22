<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ReviewCommentStatus;
use App\Enums\TaskStatus;
use App\Jobs\Workspace\RefreshPullRequestJob;
use App\Models\ConnectedAccount;
use App\Models\ReviewComment;
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
    ) {}

    /**
     * Fetch and store the pull request's state; failures keep the old one.
     * Returns the details, or null without a pull request or on failure.
     */
    /**
     * Feedback per task within one request — the sidebar, action bar and
     * review all ask for it (the service is request-scoped).
     *
     * @var array<string, array<string, mixed>>
     */
    private array $feedbackMemo = [];

    public function refresh(Task $task): ?PullRequestDetails
    {
        if ($task->pr_number === null) {
            return null;
        }
        unset($this->feedbackMemo[$task->id]);

        $workspace = $task->workspace;
        [$owner, $repo] = $workspace->ownerAndRepo();
        try {
            $details = $this->git->forWorkspace($workspace)->pullRequestDetails($owner, $repo, $task->pr_number);
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
        $task->save();

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
     * What people wrote on the pull request: reviews with text and comments,
     * without Argos' own comments (pushed commits, issue links).
     *
     * @return array{reviews: list<array{author: string, avatar?: ?string, profile?: ?string, url?: ?string, state: string, body: string, submitted_at: ?string, user: ?User}>, comments: list<array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string, user: ?User}>, reviewers: list<array{author: string, avatar?: ?string, profile?: ?string, url?: ?string, state: string, body: string, submitted_at: ?string, user: ?User}>}
     */
    public function feedback(Task $task, bool $wait = false): array
    {
        if (isset($this->feedbackMemo[$task->id])) {
            /** @var array{reviews: list<array<string, mixed>>, comments: list<array<string, mixed>>, reviewers: list<array<string, mixed>>} */
            return $this->feedbackMemo[$task->id];
        }
        $details = $task->pr_number !== null ? $this->details($task, $wait) : null;
        if ($details === null) {
            return ['reviews' => [], 'comments' => [], 'reviewers' => []];
        }

        $comments = array_values(array_filter($details->comments, fn (array $c): bool => ! self::fromArgos($c['body'])));
        $people = $this->people($task, [...array_column($details->reviews, 'author'), ...array_column($comments, 'author')]);
        $person = fn (array $entry): array => [...$entry, 'user' => $people[$entry['author']] ?? null];

        // Each reviewer once, with their latest state (for the avatar row).
        $reviewers = [];
        foreach ($details->reviews as $review) {
            $reviewers[$review['author']] = $person($review);
        }

        return $this->feedbackMemo[$task->id] = [
            'reviews' => array_map($person, array_values(array_filter($details->reviews, fn (array $r): bool => trim($r['body']) !== '' || in_array($r['state'], ['approved', 'changes_requested'], true)))),
            'comments' => array_map($person, $comments),
            'reviewers' => array_values($reviewers),
        ];
    }

    /**
     * Argos people behind provider logins (their connected account), so the
     * sidebar shows their name and profile picture.
     *
     * @param  list<string>  $logins
     * @return array<string, User> login => user
     */
    private function people(Task $task, array $logins): array
    {
        $logins = array_values(array_unique(array_filter($logins)));
        if ($logins === []) {
            return [];
        }

        return ConnectedAccount::query()
            ->with('user')
            ->where('provider', $task->workspace->platform->value)
            ->whereIn('nickname', $logins)
            ->get()
            ->filter(fn (ConnectedAccount $a): bool => $a->user !== null)
            ->mapWithKeys(fn (ConnectedAccount $a): array => [(string) $a->nickname => $a->user])
            ->all();
    }

    /**
     * Take pull request comments over into the person's own review (all,
     * or one): line comments become drafts at their line — collected with
     * one's own and sent to Claude together. Comments already taken over
     * are skipped; general comments (no line) come back for the composer.
     *
     * @return array{collected: int, general: list<array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string}>}
     */
    public function collect(Task $task, User $by, ?string $commentId = null): array
    {
        Gate::forUser($by)->authorize('prompt', $task);
        $collected = $this->collectedIds($task, $by);
        $comments = collect($this->feedback($task, wait: true)['comments'])
            ->when($commentId !== null, fn ($c) => $c->where('id', $commentId))
            ->reject(fn (array $c): bool => in_array($c['id'], $collected, true));

        $daemon = app(WorkspaceDaemonClient::class)->for($task->workspace, 15);
        $files = [];
        $collected = 0;
        foreach ($comments->filter(fn (array $c): bool => $c['path'] !== null && $c['line'] !== null) as $comment) {
            try {
                $files[$comment['path']] ??= ReviewCommentService::numbered((string) $daemon->fileContent($task->slug, $comment['path'])['content']);
            } catch (Throwable) {
                $files[$comment['path']] = [];
            }
            app(ReviewCommentService::class)->add($task, $by, $comment['path'], 'new', (int) $comment['line'], (int) $comment['line'], $comment['body'], $files[$comment['path']],
                ['id' => $comment['id'], 'author' => $comment['author'], 'url' => $comment['url'], 'avatar' => $comment['avatar'] ?? null, 'profile' => $comment['profile'] ?? null]);
            $collected++;
        }

        return ['collected' => $collected, 'general' => $comments->filter(fn (array $c): bool => $c['path'] === null || $c['line'] === null)->values()->all()];
    }

    /**
     * Ids of pull request comments the person already took over (drafts or sent).
     *
     * @return list<string>
     */
    public function collectedIds(Task $task, User $user): array
    {
        return ReviewComment::query()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereNotNull('source_id')
            ->where('status', '!=', ReviewCommentStatus::Discarded->value)
            ->pluck('source_id')->all();
    }

    /**
     * Pull request comments the person has not taken into their review yet.
     *
     * @return list<array<string, mixed>>
     */
    public function uncollectedComments(Task $task, User $user): array
    {
        $collected = $this->collectedIds($task, $user);

        return array_values(array_filter($this->feedback($task)['comments'], fn (array $c): bool => ! in_array($c['id'], $collected, true)));
    }

    /** General comments as text for the composer (they have no line to sit on). */
    public static function generalText(array $comments): string
    {
        return implode("\n", array_map(fn (array $c): string => __('workspaces.review.from_pr', ['name' => $c['author']]).' '.trim($c['body']), $comments));
    }

    /**
     * @return array<int, string> line number => text
     */
    /** Marks comments Argos writes on pull requests, so they are not read back as review. */
    public const ARGOS_MARKER = '<!-- argos -->';

    private static function fromArgos(string $body): bool
    {
        // Shipping notes from before the marker, in either language.
        return str_contains($body, self::ARGOS_MARKER)
            || preg_match('/^(New commits pushed by|Neue Commits von) /', trim($body)) === 1;
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

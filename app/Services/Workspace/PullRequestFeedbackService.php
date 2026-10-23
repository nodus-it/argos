<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ReviewCommentStatus;
use App\Models\ConnectedAccount;
use App\Models\ReviewComment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * What reviewers wrote on a task's pull request — reviews and line
 * comments without Argos' own, with the Argos people behind the logins —
 * and taking those comments into one's own review. Request-scoped: the
 * sidebar, action bar and review panel share one read per request.
 */
class PullRequestFeedbackService
{
    /**
     * Feedback per task within this request.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    public function __construct(private readonly PullRequestService $pullRequests) {}

    /** The pull request was read again: the next feedback() builds anew. */
    public function forget(Task $task): void
    {
        unset($this->memo[$task->id]);
    }

    /**
     * What people wrote on the pull request: reviews with text and comments,
     * without Argos' own comments (pushed commits, issue links).
     *
     * @return array{reviews: list<array{author: string, avatar?: ?string, profile?: ?string, url?: ?string, state: string, body: string, submitted_at: ?string, user: ?User}>, comments: list<array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string, user: ?User}>, reviewers: list<array{author: string, avatar?: ?string, profile?: ?string, url?: ?string, state: string, body: string, submitted_at: ?string, user: ?User}>}
     */
    public function feedback(Task $task, bool $wait = false): array
    {
        if (isset($this->memo[$task->id])) {
            /** @var array{reviews: list<array<string, mixed>>, comments: list<array<string, mixed>>, reviewers: list<array<string, mixed>>} */
            return $this->memo[$task->id];
        }
        $details = $task->pr_number !== null ? $this->pullRequests->details($task, $wait) : null;
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

        return $this->memo[$task->id] = [
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

    private static function fromArgos(string $body): bool
    {
        // Shipping notes from before the marker, in either language.
        return str_contains($body, PullRequestService::ARGOS_MARKER)
            || preg_match('/^(New commits pushed by|Neue Commits von) /', trim($body)) === 1;
    }
}

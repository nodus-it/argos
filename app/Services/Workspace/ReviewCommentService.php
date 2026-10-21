<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ReviewCommentStatus;
use App\Models\ReviewComment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Line comments of the review: collected per task and person (they
 * survive a reload), shown in the diff and as chips in the composer, and
 * sent to Claude together with the free text as ONE structured message.
 * Stale comments (Claude changed their lines) only go along on request,
 * with the original snippet marked as the previous version.
 */
class ReviewCommentService
{
    /** Context lines around the commented lines in the snippet. */
    public const CONTEXT = 2;

    public function __construct(private readonly TaskService $tasks) {}

    /**
     * @param  array<int, string>  $lines  line number => text, of the side commented on (with context around the range)
     */
    public function add(Task $task, User $user, string $path, string $side, int $start, int $end, string $body, array $lines): ReviewComment
    {
        Gate::forUser($user)->authorize('prompt', $task);
        [$start, $end] = [min($start, $end), max($start, $end)];

        return ReviewComment::query()->create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'path' => $path,
            'side' => $side === 'old' ? 'old' : 'new',
            'line_start' => $start,
            'line_end' => $end,
            'anchor_hash' => self::anchor($lines, $start, $end),
            'snippet' => self::snippet($lines, $start, $end),
            'body' => mb_substr(trim($body), 0, 10_000),
            'status' => ReviewCommentStatus::Draft,
        ]);
    }

    public function edit(ReviewComment $comment, User $user, string $body): void
    {
        $this->authorizeOwn($comment, $user);
        $comment->forceFill(['body' => mb_substr(trim($body), 0, 10_000)])->save();
    }

    public function discard(ReviewComment $comment, User $user): void
    {
        $this->authorizeOwn($comment, $user);
        $comment->forceFill(['status' => ReviewCommentStatus::Discarded])->save();
    }

    /**
     * Comments still collected in this task by this person.
     *
     * @return Collection<int, ReviewComment>
     */
    public function open(Task $task, User $user): Collection
    {
        return ReviewComment::query()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->whereIn('status', [ReviewCommentStatus::Draft->value, ReviewCommentStatus::Stale->value])
            ->orderBy('path')
            ->orderBy('line_start')
            ->get();
    }

    /** "Send anyway": an outdated comment goes out, marked as referring to the previous version. */
    public function sendAnyway(ReviewComment $comment, User $user): void
    {
        $this->authorizeOwn($comment, $user);
        $comment->forceFill(['status' => ReviewCommentStatus::Draft, 'outdated' => true])->save();
    }

    /**
     * "Move to the new line": re-anchor an outdated comment on what its lines
     * say now.
     *
     * @param  array<int, string>  $lines  current file, line number => text
     */
    public function moveToCurrent(ReviewComment $comment, User $user, array $lines): void
    {
        $this->authorizeOwn($comment, $user);
        $end = min($comment->line_end, max($comment->line_start, count($lines)));
        $comment->forceFill([
            'line_end' => $end,
            'anchor_hash' => self::anchor($lines, $comment->line_start, $end),
            'snippet' => self::snippet($lines, $comment->line_start, $end),
            'status' => ReviewCommentStatus::Draft,
            'outdated' => false,
        ])->save();
    }

    /**
     * Follow Claude's edits: a comment whose lines still read the same moves
     * with them (nearest match); otherwise it turns stale. Only the new side
     * changes — removed lines belong to the base branch.
     *
     * @param  array<string, string>  $contents  path => current file content (files that vanished are missing)
     * @return int comments changed
     */
    public function reanchor(Task $task, array $contents): int
    {
        $changed = 0;
        $comments = ReviewComment::query()
            ->where('task_id', $task->id)
            ->where('status', ReviewCommentStatus::Draft->value)
            ->where('outdated', false)
            ->where('side', 'new')
            ->get();

        foreach ($comments as $comment) {
            if (! array_key_exists($comment->path, $contents)) {
                continue;
            }
            $lines = self::numbered($contents[$comment->path]);
            $span = $comment->line_end - $comment->line_start;
            if (self::anchor($lines, $comment->line_start, $comment->line_end) === $comment->anchor_hash) {
                continue;
            }

            $best = null;
            foreach (array_keys($lines) as $start) {
                if (self::anchor($lines, $start, $start + $span) === $comment->anchor_hash
                    && ($best === null || abs($start - $comment->line_start) < abs($best - $comment->line_start))) {
                    $best = $start;
                }
            }

            $comment->forceFill($best !== null
                ? ['line_start' => $best, 'line_end' => $best + $span]
                : ['status' => ReviewCommentStatus::Stale])->save();
            $changed++;
        }

        return $changed;
    }

    /**
     * Paths with comments that follow the working tree (for reanchor()).
     *
     * @return list<string>
     */
    public function anchoredPaths(Task $task): array
    {
        return ReviewComment::query()
            ->where('task_id', $task->id)
            ->where('status', ReviewCommentStatus::Draft->value)
            ->where('outdated', false)
            ->where('side', 'new')
            ->distinct()
            ->pluck('path')
            ->all();
    }

    /** @return array<int, string> 1-based line number => text */
    public static function numbered(string $content): array
    {
        $lines = explode("\n", $content);

        return array_combine(range(1, count($lines)), $lines);
    }

    /**
     * Send the collected comments (outdated ones only after "send anyway")
     * with the free text as one message. Queued by the daemon while Claude
     * works.
     *
     * @return int number of comments sent
     */
    public function send(Task $task, User $user, string $text): int
    {
        $comments = $this->open($task, $user)->filter(
            fn (ReviewComment $c): bool => $c->status === ReviewCommentStatus::Draft,
        )->values();
        if ($comments->isEmpty() && trim($text) === '') {
            return 0;
        }

        $this->tasks->send($task, $user, self::message($comments, $text));
        ReviewComment::query()->whereKey($comments->pluck('id'))->update(['status' => ReviewCommentStatus::Sent->value, 'sent_at' => now()]);

        return $comments->count();
    }

    /**
     * The message Claude gets: one numbered entry per comment with location,
     * code and remark, then the free text.
     *
     * @param  Collection<int, ReviewComment>  $comments
     */
    public static function message(Collection $comments, string $text): string
    {
        $parts = [];
        if ($comments->isNotEmpty()) {
            $entries = [__('workspaces.review.message_heading')];
            foreach ($comments->values() as $i => $comment) {
                $location = $comment->location().($comment->side === 'old' ? ' '.__('workspaces.review.removed_line') : '');
                if ($comment->status === ReviewCommentStatus::Stale || $comment->outdated) {
                    $location .= ' '.__('workspaces.review.previous_version');
                }
                $code = implode("\n", array_map(fn (string $l): string => '   '.$l, explode("\n", $comment->snippet)));
                $entries[] = ($i + 1).'. '.$location."\n"
                    .'   ```'.self::language($comment->path)."\n".$code."\n   ```\n"
                    .'   → '.str_replace("\n", "\n     ", $comment->body);
            }
            $parts[] = implode("\n", $entries);
        }
        if (trim($text) !== '') {
            $parts[] = trim($text);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Hash of the commented lines' text — the anchor that re-locates a
     * comment after Claude's edits or marks it stale.
     *
     * @param  array<int, string>  $lines
     */
    public static function anchor(array $lines, int $start, int $end): string
    {
        $text = [];
        for ($n = $start; $n <= $end; $n++) {
            $text[] = rtrim($lines[$n] ?? '');
        }

        return sha1(implode("\n", $text));
    }

    /**
     * The commented lines with a little context, as code.
     *
     * @param  array<int, string>  $lines
     */
    public static function snippet(array $lines, int $start, int $end): string
    {
        $out = [];
        for ($n = max(1, $start - self::CONTEXT); $n <= $end + self::CONTEXT; $n++) {
            if (array_key_exists($n, $lines)) {
                $out[] = $lines[$n];
            }
        }

        return implode("\n", $out);
    }

    /** Code fence language from the file name. */
    public static function language(string $path): string
    {
        $name = strtolower(basename($path));

        return match (true) {
            str_ends_with($name, '.blade.php') => 'blade',
            default => match (pathinfo($name, PATHINFO_EXTENSION)) {
                'php' => 'php',
                'js', 'mjs', 'cjs' => 'js',
                'ts', 'tsx' => 'ts',
                'vue' => 'vue',
                'css' => 'css',
                'json' => 'json',
                'md' => 'md',
                'yml', 'yaml' => 'yaml',
                'sh' => 'bash',
                'html' => 'html',
                default => '',
            },
        };
    }

    private function authorizeOwn(ReviewComment $comment, User $user): void
    {
        abort_unless($comment->user_id === $user->id, 403);
    }
}

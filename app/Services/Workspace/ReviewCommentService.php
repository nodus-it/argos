<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ReviewCommentStatus;
use App\Models\ReviewComment;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Line comments of the review: collected per thread and person (they
 * survive a reload), shown in the diff and as chips in the composer, and
 * sent to Claude together with the free text as ONE structured message.
 * Stale comments (Claude changed their lines) only go along on request,
 * with the original snippet marked as the previous version.
 */
class ReviewCommentService
{
    /** Context lines around the commented lines in the snippet. */
    public const CONTEXT = 2;

    public function __construct(private readonly ThreadService $threads) {}

    /**
     * @param  array<int, string>  $lines  line number => text, of the side commented on (with context around the range)
     */
    public function add(Thread $thread, User $user, string $path, string $side, int $start, int $end, string $body, array $lines): ReviewComment
    {
        Gate::forUser($user)->authorize('prompt', $thread);
        [$start, $end] = [min($start, $end), max($start, $end)];

        return ReviewComment::query()->create([
            'thread_id' => $thread->id,
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
     * Comments still collected in this thread by this person.
     *
     * @return Collection<int, ReviewComment>
     */
    public function open(Thread $thread, User $user): Collection
    {
        return ReviewComment::query()
            ->where('thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->whereIn('status', [ReviewCommentStatus::Draft->value, ReviewCommentStatus::Stale->value])
            ->orderBy('path')
            ->orderBy('line_start')
            ->get();
    }

    /**
     * Send the active comments (and the stale ones asked for) with the free
     * text as one message. Queued by the daemon while Claude works.
     *
     * @param  list<string>  $includeStale  ids of stale comments to send anyway
     * @return int number of comments sent
     */
    public function send(Thread $thread, User $user, string $text, array $includeStale = []): int
    {
        $comments = $this->open($thread, $user)->filter(
            fn (ReviewComment $c): bool => $c->status === ReviewCommentStatus::Draft || in_array($c->id, $includeStale, true),
        )->values();
        if ($comments->isEmpty() && trim($text) === '') {
            return 0;
        }

        $this->threads->send($thread, $user, self::message($comments, $text));
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
                if ($comment->status === ReviewCommentStatus::Stale) {
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

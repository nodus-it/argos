<?php

declare(strict_types=1);

namespace App\Services\GitProvider\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Shared normalisation for the per-provider pullRequestDetails() /
 * failedCheckLog() implementations: collapsing individual check and review
 * states into one, ordering comments and bounding log text.
 */
trait SummarizesPullRequests
{
    /** Upper bound for failedCheckLog() output; the tail is kept. */
    private const int CHECK_LOG_LIMIT = 20_000;

    /** Upper bound per job/check section, so one huge log can't crowd out the others. */
    private const int CHECK_SECTION_LIMIT = 8_000;

    /**
     * @param  list<'pending'|'success'|'failure'>  $states
     * @return 'pending'|'success'|'failure'|'none'
     */
    private function summarizeCheckStates(array $states): string
    {
        return match (true) {
            in_array('failure', $states, true) => 'failure',
            in_array('pending', $states, true) => 'pending',
            in_array('success', $states, true) => 'success',
            default => 'none',
        };
    }

    /**
     * @param  list<string>  $states  per-reviewer states (approved|changes_requested|commented)
     * @return 'approved'|'changes_requested'|'commented'|null
     */
    private function summarizeReviewStates(array $states): ?string
    {
        return match (true) {
            in_array('changes_requested', $states, true) => 'changes_requested',
            in_array('approved', $states, true) => 'approved',
            in_array('commented', $states, true) => 'commented',
            default => null,
        };
    }

    /**
     * @param  list<array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string}>  $comments
     * @return list<array{id: string, author: string, avatar?: ?string, profile?: ?string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string}>
     */
    private function sortComments(array $comments): array
    {
        usort($comments, fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));

        return $comments;
    }

    /** Keeps the last $limit bytes of $text (the end of a log is where the failure is). */
    private function tail(string $text, int $limit = self::CHECK_LOG_LIMIT): string
    {
        $text = trim($text);

        if (strlen($text) <= $limit) {
            return $text;
        }

        $cut = substr($text, -$limit);
        // Don't start mid-line (or mid multi-byte character).
        $newline = strpos($cut, "\n");
        if ($newline !== false && $newline < 500) {
            $cut = substr($cut, $newline + 1);
        }

        return "…(truncated)\n".mb_scrub($cut, 'UTF-8');
    }

    /** Strips ANSI colour codes and CI section markers from a raw job log. */
    private function cleanLog(string $log): string
    {
        $log = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $log);
        $log = (string) preg_replace('/section_(start|end):\d+:[^\r\n]*\r?/', '', $log);

        return str_replace("\r\n", "\n", $log);
    }

    abstract public function label(): string;

    /**
     * Secondary detail fetches (reviews, checks, comments, …) degrade to an
     * empty array instead of failing the whole snapshot — e.g. a token that
     * may read the PR but not its checks.
     *
     * @param  callable(): mixed  $fetch
     * @return array<mixed>
     */
    private function optionalFetch(string $what, callable $fetch): array
    {
        try {
            $result = $fetch();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning("{$this->label()} {$what} unavailable", ['class' => $e::class, 'code' => $e->getCode()]);

            return [];
        }

        return is_array($result) ? $result : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}

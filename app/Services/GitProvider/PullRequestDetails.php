<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

/**
 * Provider-neutral snapshot of a pull/merge request as far as review work
 * needs it: lifecycle state, mergeability, CI checks, reviews and comments.
 * Built by GitServiceContract::pullRequestDetails() for GitHub, GitLab and
 * Bitbucket; fields a provider cannot report keep their neutral default
 * (e.g. `behindBase` null, `autoMerge` false on Bitbucket).
 *
 * @phpstan-type FailedCheck array{name: string, url: ?string}
 * @phpstan-type Checks array{state: 'pending'|'success'|'failure'|'none', url: ?string, failed: list<FailedCheck>}
 * @phpstan-type Review array{author: string, state: string, body: string, submitted_at: ?string}
 * @phpstan-type Comment array{id: string, author: string, body: string, path: ?string, line: ?int, url: ?string, created_at: ?string}
 */
final readonly class PullRequestDetails
{
    public const string STATE_DRAFT = 'draft';

    public const string STATE_OPEN = 'open';

    public const string STATE_MERGED = 'merged';

    public const string STATE_CLOSED = 'closed';

    /**
     * @param  'draft'|'open'|'merged'|'closed'  $state
     * @param  bool  $conflicts  the PR cannot be merged because of merge conflicts
     * @param  ?bool  $behindBase  the base branch moved on; null when the provider does not say
     * @param  Checks  $checks
     * @param  'approved'|'changes_requested'|'commented'|null  $reviewState
     * @param  list<Review>  $reviews
     * @param  list<Comment>  $comments  review/diff and general comments, oldest first
     * @param  bool  $autoMerge  native auto-merge / merge-when-pipeline-succeeds is set
     */
    public function __construct(
        public string $state,
        public ?string $url = null,
        public ?string $headSha = null,
        public bool $conflicts = false,
        public ?bool $behindBase = null,
        public array $checks = ['state' => 'none', 'url' => null, 'failed' => []],
        public ?string $reviewState = null,
        public array $reviews = [],
        public array $comments = [],
        public bool $autoMerge = false,
    ) {}

    /** An open PR with nothing known about it — a safe placeholder. */
    public static function empty(): self
    {
        return new self(self::STATE_OPEN);
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    /** Draft or open: the PR can still change. */
    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN || $this->state === self::STATE_DRAFT;
    }

    public function checksFailed(): bool
    {
        return $this->checks['state'] === 'failure';
    }

    public function checksPending(): bool
    {
        return $this->checks['state'] === 'pending';
    }

    /**
     * @return array{state: string, url: ?string, head_sha: ?string, conflicts: bool, behind_base: ?bool, checks: Checks, review_state: ?string, reviews: list<Review>, comments: list<Comment>, auto_merge: bool}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'url' => $this->url,
            'head_sha' => $this->headSha,
            'conflicts' => $this->conflicts,
            'behind_base' => $this->behindBase,
            'checks' => $this->checks,
            'review_state' => $this->reviewState,
            'reviews' => $this->reviews,
            'comments' => $this->comments,
            'auto_merge' => $this->autoMerge,
        ];
    }
}

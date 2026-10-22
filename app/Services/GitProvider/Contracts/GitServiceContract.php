<?php

declare(strict_types=1);

namespace App\Services\GitProvider\Contracts;

use App\Services\GitProvider\PullRequestDetails;

interface GitServiceContract
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRepositories(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBranches(string $owner, string $repo): array;

    /**
     * Returns repositories as ['owner/repo' => 'owner/repo'] for use in
     * Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getRepoOptions(): array;

    /**
     * Returns branches as ['branch' => 'branch'] for use in Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getBranchOptions(string $ownerRepo): array;

    /**
     * @param  array<string, mixed>  $options  e.g. draft, reviewers, labels
     * @return array<string, mixed>
     */
    public function createPullRequest(
        string $owner,
        string $repo,
        string $title,
        string $body,
        string $headBranch,
        string $baseBranch,
        array $options = [],
    ): array;

    /**
     * Returns the API-reported default branch for "owner/repo", or null on
     * failure (network/auth/not-found). Used by the Filament form to
     * pre-select the branch dropdown when the user picks a repo.
     */
    public function getDefaultBranch(string $ownerRepo): ?string;

    /**
     * Returns the raw contents of a file at a ref, or null when the file is
     * absent or unreadable. Used by BYOI to read `.argos/worker.dockerfile`
     * via the provider API before any worker image (and thus any clone) exists.
     */
    public function getFileContents(string $ownerRepo, string $path, string $ref): ?string;

    /**
     * Posts a comment on the given pull/merge request. PR identifier types
     * differ per provider (GitHub `int $number`, GitLab `int $iid`,
     * Bitbucket `int $id`) — `int|string` keeps the contract agnostic.
     *
     * @return array<string, mixed>
     */
    public function commentOnPullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $body,
    ): array;

    /**
     * Raw API representation of a pull/merge request (GitHub `number`,
     * GitLab `iid`, Bitbucket `id`). Read through PullRequestInfo for a
     * provider-neutral view of number, URL and state.
     *
     * @return array<string, mixed>
     */
    public function getPullRequest(string $owner, string $repo, int|string $pullRequestId): array;

    /**
     * Provider-neutral review snapshot of a pull/merge request: state
     * (draft|open|merged|closed), head SHA, conflicts/behind-base, CI checks,
     * review state, reviews, comments (oldest first) and auto-merge.
     */
    public function pullRequestDetails(string $owner, string $repo, int|string $number): PullRequestDetails;

    /**
     * Takes a draft pull/merge request out of draft. A no-op when it is not a
     * draft; throws RuntimeException when the provider cannot do it.
     */
    public function markPullRequestReady(string $owner, string $repo, int|string $number): void;

    /**
     * Turns on the provider's native auto-merge (GitHub auto-merge, GitLab
     * merge-when-pipeline-succeeds). Returns false — never throws — when the
     * repository/provider does not allow or support it.
     */
    public function enableAutoMerge(string $owner, string $repo, int|string $number): bool;

    /**
     * Turns native auto-merge off again. Returns true when it is off
     * afterwards (also when it was never on), false — never throws — when the
     * provider refused or does not support it (Bitbucket).
     */
    public function disableAutoMerge(string $owner, string $repo, int|string $number): bool;

    /**
     * Plain-text digest of the failed CI checks of the PR head (job log tails,
     * check output, annotations), at most ~20 KB with the end kept. Empty
     * string when nothing failed.
     */
    public function failedCheckLog(string $owner, string $repo, int|string $number): string;
}

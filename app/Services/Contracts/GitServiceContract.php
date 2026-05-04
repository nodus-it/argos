<?php

declare(strict_types=1);

namespace App\Services\Contracts;

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
     * Posts a comment on the given pull/merge request. Used by the worker's
     * push phase to log iteration progress on the PR. PR identifier types
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
     * Updates the title and description of an existing pull/merge request.
     * Used by the worker's push phase when re-opening or refreshing a PR
     * after an additional implementation iteration.
     *
     * @return array<string, mixed>
     */
    public function updatePullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $title,
        string $body,
    ): array;
}

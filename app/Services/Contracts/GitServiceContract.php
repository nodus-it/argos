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
}

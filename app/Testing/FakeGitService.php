<?php

declare(strict_types=1);

namespace App\Testing;

use App\Services\GitProvider\Contracts\GitProviderContract;

/**
 * Browser-E2E fake git provider: serves canonical repo/branch data so the
 * RepoProfile form's dropdowns fill in without any external API call, and
 * returns plausible PR payloads. Bound per platform by E2eFakeServiceProvider
 * (env-gated, never in production).
 *
 * The data is intentionally platform-agnostic — the suite only needs the
 * dropdowns populated and a stable default branch; which platform/agent/auth a
 * run exercises is decided by the seeded RepoProfile, not by this service.
 */
class FakeGitService implements GitProviderContract
{
    public function __construct(
        private readonly string $providerKey = 'github',
        private readonly string $label = 'Fake Provider',
    ) {}

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRepositories(): array
    {
        return [
            ['full_name' => 'argos-e2e/demo-app', 'default_branch' => 'main'],
            ['full_name' => 'argos-e2e/widget', 'default_branch' => 'main'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBranches(string $owner, string $repo): array
    {
        return [
            ['name' => 'main'],
            ['name' => 'develop'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getRepoOptions(): array
    {
        return [
            'argos-e2e/demo-app' => 'argos-e2e/demo-app',
            'argos-e2e/widget' => 'argos-e2e/widget',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getBranchOptions(string $ownerRepo): array
    {
        return ['main' => 'main', 'develop' => 'develop'];
    }

    public function getDefaultBranch(string $ownerRepo): ?string
    {
        return 'main';
    }

    public function getFileContents(string $ownerRepo, string $path, string $ref): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $options
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
    ): array {
        return [
            'number' => 1,
            'url' => "https://example.test/{$owner}/{$repo}/pull/1",
            'title' => $title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commentOnPullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $body,
    ): array {
        return ['id' => 1, 'body' => $body];
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $title,
        string $body,
    ): array {
        return ['number' => $pullRequestId, 'title' => $title, 'body' => $body];
    }
}

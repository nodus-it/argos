<?php

declare(strict_types=1);

namespace App\Testing;

use App\Services\GitProvider\Contracts\GitProviderContract;
use App\Services\GitProvider\PullRequestDetails;

/**
 * Browser-E2E fake git provider: serves canonical repo/branch data so the
 * workspace form's dropdowns fill in without any external API call, and
 * returns plausible PR payloads. Bound per platform by E2eFakeServiceProvider
 * (env-gated, never in production).
 *
 * The data is intentionally platform-agnostic — the suite only needs the
 * dropdowns populated and a stable default branch; which platform/agent/auth a
 * run exercises is decided by the seeded Workspace, not by this service.
 */
class FakeGitService implements GitProviderContract
{
    public function __construct(
        private readonly string $providerKey = 'github',
        private readonly string $label = 'Fake Provider',
    ) {}

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

    public function findOpenPullRequest(string $owner, string $repo, string $headBranch): ?array
    {
        return null;
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
    public function getPullRequest(string $owner, string $repo, int|string $pullRequestId): array
    {
        return ['number' => (int) $pullRequestId, 'html_url' => "https://example.test/{$owner}/{$repo}/pull/{$pullRequestId}", 'state' => 'open', 'merged' => false];
    }

    /**
     * A healthy open PR: green checks, no conflicts, one inline review
     * comment — enough for the review panel to render every section.
     */
    public function pullRequestDetails(string $owner, string $repo, int|string $number): PullRequestDetails
    {
        $url = "https://example.test/{$owner}/{$repo}/pull/{$number}";

        return new PullRequestDetails(
            state: PullRequestDetails::STATE_OPEN,
            url: $url,
            headSha: 'e2e0000000000000000000000000000000000000',
            conflicts: false,
            behindBase: false,
            checks: ['state' => 'success', 'url' => "{$url}/checks", 'failed' => []],
            reviewState: 'commented',
            reviews: [
                ['author' => 'e2e-reviewer', 'state' => 'commented', 'body' => 'Looks good overall.', 'submitted_at' => '2026-01-01T10:00:00Z'],
            ],
            comments: [
                ['id' => '1', 'author' => 'e2e-reviewer', 'body' => 'Please add a test for this branch.', 'path' => 'app/Example.php', 'line' => 12, 'url' => "{$url}#discussion_r1", 'created_at' => '2026-01-01T10:00:00Z'],
            ],
            autoMerge: false,
        );
    }

    public function markPullRequestReady(string $owner, string $repo, int|string $number): void {}

    public function enableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        return true;
    }

    public function disableAutoMerge(string $owner, string $repo, int|string $number): bool
    {
        return true;
    }

    public function failedCheckLog(string $owner, string $repo, int|string $number): string
    {
        return '';
    }
}

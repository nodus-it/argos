<?php

declare(strict_types=1);

namespace Tests\External\Providers\GitHub;

use App\Services\Contracts\GitProviderContract;
use App\Services\GitHub\GitHubGitService;
use Tests\External\ProviderContractTestCase;
use Tests\External\Support\ProviderTestConfig;

final class GitHubContractTest extends ProviderContractTestCase
{
    protected function makeConfig(): ProviderTestConfig
    {
        return ProviderTestConfig::fromEnv('github');
    }

    protected function makeService(string $token): GitProviderContract
    {
        return new GitHubGitService($token);
    }

    protected function pullRequestId(array $createResponse): int|string
    {
        return (int) ($createResponse['number'] ?? 0);
    }

    protected function closePullRequestViaApi(int|string $id, string $token): void
    {
        $owner = $this->config->testRepoOwner;
        $repo = $this->config->testRepo;

        $this->http($token, ['X-GitHub-Api-Version' => '2022-11-28'])
            ->patch("https://api.github.com/repos/{$owner}/{$repo}/pulls/{$id}", ['state' => 'closed'])
            ->throw();
    }
}

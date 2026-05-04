<?php

declare(strict_types=1);

namespace Tests\External\Providers\GitLab;

use App\Services\Contracts\GitProviderContract;
use App\Services\GitLab\GitLabGitService;
use Tests\External\ProviderContractTestCase;
use Tests\External\Support\ProviderTestConfig;

final class GitLabContractTest extends ProviderContractTestCase
{
    protected function makeConfig(): ProviderTestConfig
    {
        return ProviderTestConfig::fromEnv('gitlab');
    }

    protected function makeService(string $token): GitProviderContract
    {
        return new GitLabGitService($token, $this->config->instanceUrl);
    }

    protected function pullRequestId(array $createResponse): int|string
    {
        return (int) ($createResponse['iid'] ?? 0);
    }

    protected function closePullRequestViaApi(int|string $id, string $token): void
    {
        $projectPath = urlencode("{$this->config->testRepoOwner}/{$this->config->testRepo}");
        $base = rtrim($this->config->instanceUrl, '/').'/api/v4';

        $this->http($token)
            ->put("{$base}/projects/{$projectPath}/merge_requests/{$id}", ['state_event' => 'close'])
            ->throw();
    }
}

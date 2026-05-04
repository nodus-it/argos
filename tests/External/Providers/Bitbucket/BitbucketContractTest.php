<?php

declare(strict_types=1);

namespace Tests\External\Providers\Bitbucket;

use App\Services\Bitbucket\BitbucketGitService;
use App\Services\Contracts\GitProviderContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Tests\External\ProviderContractTestCase;
use Tests\External\Support\ProviderTestConfig;

final class BitbucketContractTest extends ProviderContractTestCase
{
    protected function makeConfig(): ProviderTestConfig
    {
        return ProviderTestConfig::fromEnv('bitbucket');
    }

    protected function makeService(string $token): GitProviderContract
    {
        return new BitbucketGitService($token);
    }

    protected function pullRequestId(array $createResponse): int|string
    {
        return (int) ($createResponse['id'] ?? 0);
    }

    protected function closePullRequestViaApi(int|string $id, string $token): void
    {
        $owner = $this->config->testRepoOwner;
        $repo = $this->config->testRepo;

        // Bitbucket has no "close" — only "decline" leaves the PR persisted but inactive.
        $this->bitbucketHttp($token)
            ->post("https://api.bitbucket.org/2.0/repositories/{$owner}/{$repo}/pullrequests/{$id}/decline")
            ->throw();
    }

    /**
     * Bitbucket diverges on auth: PATs are "username:app_password" and use
     * HTTP Basic, OAuth tokens use Bearer. Detected by presence of a colon
     * in the token (mirrors BitbucketGitService).
     */
    private function bitbucketHttp(string $token): PendingRequest
    {
        if (str_contains($token, ':')) {
            [$username, $appPassword] = explode(':', $token, 2);

            return Http::withBasicAuth($username, $appPassword)
                ->withHeaders(['Accept' => 'application/json']);
        }

        return Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }
}

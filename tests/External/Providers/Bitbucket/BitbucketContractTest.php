<?php

declare(strict_types=1);

namespace Tests\External\Providers\Bitbucket;

use App\Services\GitProvider\BitbucketGitService;
use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Tests\External\ProviderContractTestCase;
use Tests\External\Support\ProviderTestConfig;

final class BitbucketContractTest extends ProviderContractTestCase
{
    /**
     * `listRepositories` is a user-level workspace discovery operation —
     * GET /2.0/workspaces requires the `account` scope, which Repository
     * Access Tokens do not carry (CHANGE-2770 also retired the older
     * /user/permissions/workspaces fallback). The Filament form mirrors
     * this: the Bitbucket PAT path shows a free-text input, not a repo
     * dropdown, so this method only ever runs in the OAuth path. We
     * exercise the OAuth path manually via `test:providers`, not from
     * the External suite.
     */
    public function test_list_repositories_includes_test_repo(): void
    {
        $this->markTestSkipped(
            'Bitbucket Repository Access Tokens lack workspace-level scope; listRepositories is OAuth-only.'
        );
    }

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

        // Bitbucket has no "close" — only "decline" leaves the PR persisted but
        // inactive. The endpoint demands a JSON body even though all fields are
        // optional; an empty object satisfies the validator.
        $this->bitbucketHttp($token)
            ->asJson()
            ->post(
                "https://api.bitbucket.org/2.0/repositories/{$owner}/{$repo}/pullrequests/{$id}/decline",
                (object) [],
            )
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

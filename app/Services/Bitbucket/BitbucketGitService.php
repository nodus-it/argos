<?php

declare(strict_types=1);

namespace App\Services\Bitbucket;

use App\Services\Contracts\GitProviderContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BitbucketGitService implements GitProviderContract
{
    private const BASE_URL = 'https://api.bitbucket.org/2.0';

    private readonly string $username;

    private readonly string $appPassword;

    private readonly bool $isOAuth;

    public function __construct(private readonly string $token)
    {
        // PAT format: "username:app_password" — OAuth tokens contain no colon.
        if (str_contains($token, ':')) {
            [$this->username, $this->appPassword] = explode(':', $token, 2);
            $this->isOAuth = false;
        } else {
            $this->username = '';
            $this->appPassword = '';
            $this->isOAuth = true;
        }
    }

    public function getProviderKey(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket';
    }

    public function listRepositories(): array
    {
        // Atlassian removed the cross-workspace /repositories endpoint (CHANGE-2770).
        // The replacement is per-workspace, so we fan out: list workspaces the user
        // has access to, then list repos within each.
        $workspaces = $this->http()
            ->get('/user/permissions/workspaces', ['pagelen' => 100])
            ->throw()
            ->json('values', []);

        $repos = [];
        foreach ($workspaces as $perm) {
            $slug = $perm['workspace']['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $values = $this->http()
                ->get("/repositories/{$slug}", ['role' => 'member', 'pagelen' => 100])
                ->throw()
                ->json('values', []);

            foreach ($values as $repo) {
                $repos[] = $repo;
            }
        }

        return $repos;
    }

    public function listBranches(string $owner, string $repo): array
    {
        $response = $this->http()
            ->get("/repositories/{$owner}/{$repo}/refs/branches", ['pagelen' => 100]);
        $response->throw();

        return $response->json('values', []);
    }

    public function createPullRequest(
        string $owner,
        string $repo,
        string $title,
        string $body,
        string $headBranch,
        string $baseBranch,
        array $options = [],
    ): array {
        return $this->http()
            ->post("/repositories/{$owner}/{$repo}/pullrequests", [
                'title' => $title,
                'description' => $body,
                'source' => ['branch' => ['name' => $headBranch]],
                'destination' => ['branch' => ['name' => $baseBranch]],
                ...$options,
            ])
            ->throw()
            ->json();
    }

    public function commentOnPullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $body,
    ): array {
        // Bitbucket nests the body under content.raw, unlike GitHub/GitLab.
        return $this->http()
            ->post("/repositories/{$owner}/{$repo}/pullrequests/{$pullRequestId}/comments", [
                'content' => ['raw' => $body],
            ])
            ->throw()
            ->json();
    }

    public function updatePullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $title,
        string $body,
    ): array {
        return $this->http()
            ->put("/repositories/{$owner}/{$repo}/pullrequests/{$pullRequestId}", [
                'title' => $title,
                'description' => $body,
            ])
            ->throw()
            ->json();
    }

    /**
     * Returns repos as ['workspace/slug' => 'workspace/slug'] for Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getRepoOptions(): array
    {
        $repos = $this->listRepositories();

        $options = [];
        foreach ($repos as $repo) {
            $fullName = $repo['full_name'] ?? null;
            if (is_string($fullName) && $fullName !== '') {
                $options[$fullName] = $fullName;
            }
        }

        return $options;
    }

    /**
     * Returns branches as ['branch' => 'branch'] for Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getBranchOptions(string $ownerRepo): array
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return [];
        }

        $branches = $this->listBranches($owner, $repo);

        $options = [];
        foreach ($branches as $branch) {
            $name = $branch['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $options[$name] = $name;
            }
        }

        return $options;
    }

    /**
     * Returns the default branch for a workspace/repo string, or null on failure.
     */
    public function getDefaultBranch(string $ownerRepo): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        try {
            $data = $this->http()
                ->get("/repositories/{$owner}/{$repo}")
                ->throw()
                ->json();
        } catch (\Throwable) {
            return null;
        }

        $branch = $data['mainbranch']['name'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
    }

    private function http(): PendingRequest
    {
        if ($this->isOAuth) {
            return Http::withHeaders([
                'Authorization' => "Bearer {$this->token}",
                'Accept' => 'application/json',
            ])->baseUrl(self::BASE_URL);
        }

        return Http::withBasicAuth($this->username, $this->appPassword)
            ->withHeaders(['Accept' => 'application/json'])
            ->baseUrl(self::BASE_URL);
    }
}

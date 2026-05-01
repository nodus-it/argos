<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Services\Contracts\GitServiceContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubGitService implements GitServiceContract
{
    private const BASE_URL = 'https://api.github.com';

    private const API_VERSION = '2022-11-28';

    public function __construct(private readonly string $token) {}

    public function listRepositories(): array
    {
        return $this->http()
            ->get('/user/repos', ['per_page' => 100, 'sort' => 'updated', 'affiliation' => 'owner,collaborator'])
            ->throw()
            ->json();
    }

    public function listBranches(string $owner, string $repo): array
    {
        return $this->http()
            ->get("/repos/{$owner}/{$repo}/branches", ['per_page' => 100])
            ->throw()
            ->json();
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
            ->post("/repos/{$owner}/{$repo}/pulls", [
                'title' => $title,
                'body' => $body,
                'head' => $headBranch,
                'base' => $baseBranch,
                ...$options,
            ])
            ->throw()
            ->json();
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
        ])->baseUrl(self::BASE_URL);
    }
}

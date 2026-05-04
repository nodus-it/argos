<?php

declare(strict_types=1);

namespace App\Services\GitLab;

use App\Services\Contracts\GitProviderContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitLabGitService implements GitProviderContract
{
    public function __construct(
        private readonly string $token,
        private readonly string $instanceUrl = 'https://gitlab.com',
    ) {}

    public function getProviderKey(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab';
    }

    public function listRepositories(): array
    {
        return $this->http()
            ->get('/projects', ['membership' => true, 'per_page' => 100, 'order_by' => 'last_activity_at'])
            ->throw()
            ->json();
    }

    public function listBranches(string $owner, string $repo): array
    {
        $projectPath = $this->encodePath($owner, $repo);

        return $this->http()
            ->get("/projects/{$projectPath}/repository/branches", ['per_page' => 100])
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
        $projectPath = $this->encodePath($owner, $repo);

        return $this->http()
            ->post("/projects/{$projectPath}/merge_requests", [
                'title' => $title,
                'description' => $body,
                'source_branch' => $headBranch,
                'target_branch' => $baseBranch,
                ...$options,
            ])
            ->throw()
            ->json();
    }

    private function encodePath(string $owner, string $repo): string
    {
        return urlencode("{$owner}/{$repo}");
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'PRIVATE-TOKEN' => $this->token,
            'Content-Type' => 'application/json',
        ])->baseUrl("{$this->instanceUrl}/api/v4");
    }
}

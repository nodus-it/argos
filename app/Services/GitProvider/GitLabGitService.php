<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * Returns the API-reported default branch of the given owner/repo,
     * or null if the call fails (network/auth/not-found).
     */
    public function getDefaultBranch(string $ownerRepo): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        $projectPath = $this->encodePath($owner, $repo);

        try {
            $data = $this->http()
                ->get("/projects/{$projectPath}")
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitLab getDefaultBranch failed', [
                'owner_repo' => "{$owner}/{$repo}",
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }

        $branch = $data['default_branch'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
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

    public function commentOnPullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $body,
    ): array {
        $projectPath = $this->encodePath($owner, $repo);

        return $this->http()
            ->post("/projects/{$projectPath}/merge_requests/{$pullRequestId}/notes", ['body' => $body])
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
        $projectPath = $this->encodePath($owner, $repo);

        return $this->http()
            ->put("/projects/{$projectPath}/merge_requests/{$pullRequestId}", [
                'title' => $title,
                'description' => $body,
            ])
            ->throw()
            ->json();
    }

    /**
     * Returns projects as ['namespace/name' => 'namespace/name'] for use in Filament Select dropdowns.
     *
     * @return array<string, string>
     */
    public function getRepoOptions(): array
    {
        $projects = $this->listRepositories();

        $options = [];
        foreach ($projects as $project) {
            $path = $project['path_with_namespace'] ?? null;
            if (is_string($path) && $path !== '') {
                $options[$path] = $path;
            }
        }

        return $options;
    }

    /**
     * Returns branches as ['branch' => 'branch'] for use in Filament Select dropdowns.
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

    private function encodePath(string $owner, string $repo): string
    {
        return urlencode("{$owner}/{$repo}");
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Content-Type' => 'application/json',
        ])->baseUrl("{$this->instanceUrl}/api/v4");
    }
}

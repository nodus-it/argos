<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Integrations\GitLab\GitLabConnector;
use App\Integrations\GitLab\Requests\CommentOnMergeRequest;
use App\Integrations\GitLab\Requests\CreateMergeRequest;
use App\Integrations\GitLab\Requests\GetProject;
use App\Integrations\GitLab\Requests\GetRawFile;
use App\Integrations\GitLab\Requests\ListBranches;
use App\Integrations\GitLab\Requests\ListProjects;
use App\Integrations\GitLab\Requests\UpdateMergeRequest;
use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Support\Facades\Log;

class GitLabGitService implements GitProviderContract
{
    private readonly GitLabConnector $connector;

    public function __construct(string $token, string $instanceUrl = 'https://gitlab.com')
    {
        $this->connector = new GitLabConnector($token, $instanceUrl);
    }

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
        return $this->connector->send(new ListProjects)->throw()->json();
    }

    public function listBranches(string $owner, string $repo): array
    {
        return $this->connector->send(new ListBranches($owner, $repo))->throw()->json();
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

        try {
            $data = $this->connector->send(new GetProject($owner, $repo))->throw()->json();
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

    public function getFileContents(string $ownerRepo, string $path, string $ref): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        try {
            $response = $this->connector->send(new GetRawFile($owner, $repo, $path, $ref));

            if ($response->status() === 404) {
                return null;
            }

            return $response->throw()->body();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitLab getFileContents failed', [
                'owner_repo' => "{$owner}/{$repo}",
                'path' => $path,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }
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
        return $this->connector
            ->send(new CreateMergeRequest($owner, $repo, $title, $body, $headBranch, $baseBranch, $options))
            ->throw()
            ->json();
    }

    public function commentOnPullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $body,
    ): array {
        return $this->connector
            ->send(new CommentOnMergeRequest($owner, $repo, $pullRequestId, $body))
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
        return $this->connector
            ->send(new UpdateMergeRequest($owner, $repo, $pullRequestId, $title, $body))
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
}

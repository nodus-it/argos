<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Integrations\Bitbucket\BitbucketConnector;
use App\Integrations\Bitbucket\Requests\CommentOnPullRequest;
use App\Integrations\Bitbucket\Requests\CreatePullRequest;
use App\Integrations\Bitbucket\Requests\GetRepository;
use App\Integrations\Bitbucket\Requests\GetSourceFile;
use App\Integrations\Bitbucket\Requests\ListBranches;
use App\Integrations\Bitbucket\Requests\ListUserWorkspaces;
use App\Integrations\Bitbucket\Requests\ListWorkspaceRepositories;
use App\Integrations\Bitbucket\Requests\UpdatePullRequest;
use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Support\Facades\Log;

class BitbucketGitService implements GitProviderContract
{
    private readonly BitbucketConnector $connector;

    public function __construct(string $token)
    {
        // Auth (Basic vs. Bearer) is resolved by the connector from the token shape.
        $this->connector = new BitbucketConnector($token);
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
        // Atlassian's CHANGE-2770 deprecation walked through three endpoints:
        // /repositories, /user/permissions/workspaces, and /workspaces are all
        // gone. /2.0/user/workspaces is the surviving (and explicitly announced)
        // replacement — values are workspace_access records with the slug
        // nested under .workspace.slug.
        $workspaces = $this->connector->send(new ListUserWorkspaces)->throw()->json('values', []);

        $repos = [];
        foreach ($workspaces as $access) {
            $slug = $access['workspace']['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $values = $this->connector
                ->send(new ListWorkspaceRepositories($slug))
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
        return $this->connector->send(new ListBranches($owner, $repo))->throw()->json('values', []);
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
            ->send(new CreatePullRequest($owner, $repo, $title, $body, $headBranch, $baseBranch, $options))
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
            ->send(new CommentOnPullRequest($owner, $repo, $pullRequestId, $body))
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
            ->send(new UpdatePullRequest($owner, $repo, $pullRequestId, $title, $body))
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
            $data = $this->connector->send(new GetRepository($owner, $repo))->throw()->json();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('Bitbucket getDefaultBranch failed', [
                'owner_repo' => "{$owner}/{$repo}",
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }

        $branch = $data['mainbranch']['name'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
    }

    public function getFileContents(string $ownerRepo, string $path, string $ref): ?string
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];

        if ($owner === '' || $repo === '') {
            return null;
        }

        try {
            $response = $this->connector->send(new GetSourceFile($owner, $repo, $path, $ref));

            if ($response->status() === 404) {
                return null;
            }

            return $response->throw()->body();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('Bitbucket getFileContents failed', [
                'owner_repo' => "{$owner}/{$repo}",
                'path' => $path,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }
    }
}

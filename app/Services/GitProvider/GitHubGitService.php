<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Integrations\GitHub\GitHubConnector;
use App\Integrations\GitHub\Requests\CommentOnPullRequest;
use App\Integrations\GitHub\Requests\CreatePullRequest;
use App\Integrations\GitHub\Requests\GetFileContents;
use App\Integrations\GitHub\Requests\GetRepository;
use App\Integrations\GitHub\Requests\ListBranches;
use App\Integrations\GitHub\Requests\ListRepositories;
use App\Integrations\GitHub\Requests\UpdatePullRequest;
use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Support\Facades\Log;

class GitHubGitService implements GitProviderContract
{
    private readonly GitHubConnector $connector;

    public function __construct(string $token)
    {
        $this->connector = new GitHubConnector($token);
    }

    public function getProviderKey(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function listRepositories(): array
    {
        return $this->connector->send(new ListRepositories)->throw()->json();
    }

    public function listBranches(string $owner, string $repo): array
    {
        return $this->connector->send(new ListBranches($owner, $repo))->throw()->json();
    }

    /**
     * Fetch repository metadata. Used to pre-select the API-reported
     * default_branch when the user picks a repo in the form.
     *
     * @return array<string, mixed>
     */
    public function getRepository(string $owner, string $repo): array
    {
        return $this->connector->send(new GetRepository($owner, $repo))->throw()->json();
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
            $data = $this->getRepository($owner, $repo);
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitHub getDefaultBranch failed', [
                'owner_repo' => $ownerRepo,
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
            $response = $this->connector->send(new GetFileContents($owner, $repo, $path, $ref));

            if ($response->status() === 404) {
                return null;
            }

            $data = $response->throw()->json();
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('GitHub getFileContents failed', [
                'owner_repo' => $ownerRepo,
                'path' => $path,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }

        $content = $data['content'] ?? null;
        if (! is_string($content)) {
            return null;
        }

        $decoded = base64_decode(str_replace("\n", '', $content), true);

        return $decoded === false ? null : $decoded;
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
     * Returns repos as ['owner/repo' => 'owner/repo'] for use in Filament Select dropdowns.
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

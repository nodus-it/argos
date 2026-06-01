<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Services\GitProvider\Contracts\GitProviderContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubGitService implements GitProviderContract
{
    private const BASE_URL = 'https://api.github.com';

    private const API_VERSION = '2022-11-28';

    public function __construct(private readonly string $token) {}

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
        return $this->http()
            ->get('/user/repos', ['per_page' => 100, 'sort' => 'updated', 'affiliation' => 'owner,collaborator,organization_member'])
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

    /**
     * Fetch repository metadata. Used to pre-select the API-reported
     * default_branch when the user picks a repo in the form.
     *
     * @return array<string, mixed>
     */
    public function getRepository(string $owner, string $repo): array
    {
        return $this->http()
            ->get("/repos/{$owner}/{$repo}")
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
            $response = $this->http()->get(
                "/repos/{$owner}/{$repo}/contents/".ltrim($path, '/'),
                ['ref' => $ref],
            );

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

    public function commentOnPullRequest(
        string $owner,
        string $repo,
        int|string $pullRequestId,
        string $body,
    ): array {
        // GitHub treats PRs as a special kind of issue; PR comments live on the
        // issues/{number}/comments endpoint, with the PR number used as issue id.
        return $this->http()
            ->post("/repos/{$owner}/{$repo}/issues/{$pullRequestId}/comments", ['body' => $body])
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
            ->patch("/repos/{$owner}/{$repo}/pulls/{$pullRequestId}", [
                'title' => $title,
                'body' => $body,
            ])
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

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
        ])->baseUrl(self::BASE_URL);
    }
}

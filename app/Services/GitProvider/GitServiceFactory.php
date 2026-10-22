<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Enums\GitProvider as GitProviderEnum;
use App\Models\Workspace;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Support\RepoUrlBuilder;

class GitServiceFactory
{
    public function __construct(private readonly GitProviderRegistry $registry) {}

    public function forWorkspace(Workspace $workspace): GitServiceContract
    {
        $token = $workspace->resolveToken();
        $instanceUrl = $workspace->platform === GitProviderEnum::GitLab
            ? $this->extractInstanceUrl($workspace->repo_url)
            : '';

        return $this->registry->make($workspace->platform->value, $token, $instanceUrl);
    }

    /**
     * Build a service from a raw token, for use cases (e.g. OAuth) that have no workspace yet.
     */
    public function forPlatform(string $platform, string $token, string $instanceUrl = ''): GitServiceContract
    {
        return $this->registry->make($platform, $token, $instanceUrl);
    }

    private function extractInstanceUrl(string $repoUrl): string
    {
        return RepoUrlBuilder::instanceOf($repoUrl);
    }
}

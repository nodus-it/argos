<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Support\RepoUrlBuilder;
use App\Enums\GitProvider as GitProviderEnum;
use App\Models\Workspace;
use App\Services\GitProvider\Contracts\GitServiceContract;

class GitServiceFactory
{
    public function __construct(private readonly GitProviderRegistry $registry) {}

    public function forWorkspace(Workspace $profile): GitServiceContract
    {
        $token = $profile->resolveToken();
        $instanceUrl = $profile->platform === GitProviderEnum::GitLab
            ? $this->extractInstanceUrl($profile->repo_url)
            : '';

        return $this->registry->make($profile->platform->value, $token, $instanceUrl);
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

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GitProvider;
use App\Models\RepoProfile;
use App\Services\Contracts\GitServiceContract;

class GitServiceFactory
{
    public function __construct(private readonly GitProviderRegistry $registry) {}

    public function fromRepoProfile(RepoProfile $profile): GitServiceContract
    {
        $token = $profile->resolveToken();
        $instanceUrl = $profile->platform === GitProvider::GitLab
            ? $this->extractInstanceUrl($profile->url)
            : '';

        return $this->registry->make($profile->platform->value, $token, $instanceUrl);
    }

    /**
     * Build a service from a raw token, for use cases (e.g. OAuth) that have no RepoProfile yet.
     */
    public function forPlatform(string $platform, string $token, string $instanceUrl = ''): GitServiceContract
    {
        return $this->registry->make($platform, $token, $instanceUrl);
    }

    private function extractInstanceUrl(string $repoUrl): string
    {
        $parsed = parse_url($repoUrl);

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? 'gitlab.com');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RepoProfile;
use App\Services\Contracts\GitServiceContract;
use App\Services\GitHub\GitHubGitService;
use App\Services\GitLab\GitLabGitService;
use InvalidArgumentException;

class GitServiceFactory
{
    public function fromRepoProfile(RepoProfile $profile): GitServiceContract
    {
        return match ($profile->platform) {
            'github' => new GitHubGitService($profile->token),
            'gitlab' => new GitLabGitService(
                token: $profile->token,
                instanceUrl: $this->extractInstanceUrl($profile->url),
            ),
            default => throw new InvalidArgumentException("Unbekannte Platform: {$profile->platform}"),
        };
    }

    /**
     * Build a service from a raw token, for use cases (e.g. OAuth) that have no RepoProfile yet.
     */
    public function forPlatform(string $platform, string $token, string $instanceUrl = ''): GitServiceContract
    {
        return match ($platform) {
            'github' => new GitHubGitService($token),
            'gitlab' => new GitLabGitService($token, $instanceUrl ?: 'https://gitlab.com'),
            default => throw new InvalidArgumentException("Unbekannte Platform: {$platform}"),
        };
    }

    private function extractInstanceUrl(string $repoUrl): string
    {
        $parsed = parse_url($repoUrl);

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? 'gitlab.com');
    }
}

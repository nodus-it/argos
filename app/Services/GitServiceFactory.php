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
     * Für zukünftige OAuth-Flows: Token direkt übergeben ohne RepoProfile.
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

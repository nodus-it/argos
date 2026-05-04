<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RepoProfile;
use App\Services\Bitbucket\BitbucketTaskService;
use App\Services\Contracts\TaskServiceContract;
use App\Services\GitHub\GitHubTaskService;
use App\Services\GitLab\GitLabTaskService;
use InvalidArgumentException;

class TaskServiceFactory
{
    public function fromRepoProfile(RepoProfile $profile): TaskServiceContract
    {
        return match ($profile->platform) {
            'github' => new GitHubTaskService($profile->token),
            'gitlab' => new GitLabTaskService(
                token: $profile->token,
                instanceUrl: $this->extractInstanceUrl($profile->url),
            ),
            'bitbucket' => new BitbucketTaskService($profile->token),
            default => throw new InvalidArgumentException("Unbekannte Platform: {$profile->platform}"),
        };
    }

    /**
     * Build a service from a raw token, for use cases (e.g. OAuth) that have no RepoProfile yet.
     */
    public function forPlatform(string $platform, string $token, string $instanceUrl = ''): TaskServiceContract
    {
        return match ($platform) {
            'github' => new GitHubTaskService($token),
            'gitlab' => new GitLabTaskService($token, $instanceUrl ?: 'https://gitlab.com'),
            'bitbucket' => new BitbucketTaskService($token),
            default => throw new InvalidArgumentException("Unbekannte Platform: {$platform}"),
        };
    }

    private function extractInstanceUrl(string $repoUrl): string
    {
        $parsed = parse_url($repoUrl);

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? 'gitlab.com');
    }
}

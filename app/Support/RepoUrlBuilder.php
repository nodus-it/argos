<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Repository URLs in one place: the clone URL of an "owner/repo" (or GitLab
 * "group/sub/repo") path per platform, the path and instance of a URL, and
 * the public GitLab host every GitLab default falls back to.
 */
class RepoUrlBuilder
{
    /** Public GitLab host, used when no self-hosted instance URL is given. */
    public const DEFAULT_GITLAB_INSTANCE = 'https://gitlab.com';

    /**
     * @param  string  $platform  'github' | 'gitlab' | 'bitbucket'
     * @param  string  $repo  "owner/repo" path
     * @param  string|null  $instanceUrl  self-hosted GitLab host; falls back to gitlab.com
     * @return string the clone URL, or '' for an unknown platform
     */
    public static function build(string $platform, string $repo, ?string $instanceUrl = null): string
    {
        return match ($platform) {
            'github' => "https://github.com/{$repo}",
            'gitlab' => self::gitlabInstance($instanceUrl)."/{$repo}",
            'bitbucket' => "https://bitbucket.org/{$repo}",
            default => '',
        };
    }

    /** "owner/repo" (or a GitLab group path) of a repository URL; null without a path. */
    public static function pathOf(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }
        $path = rtrim($path, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }
        $path = ltrim($path, '/');

        return $path !== '' ? $path : null;
    }

    /** The instance a repository URL lives on ("https://gitlab.example.com"). */
    public static function instanceOf(string $url): string
    {
        $parsed = parse_url($url);

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? parse_url(self::DEFAULT_GITLAB_INSTANCE, PHP_URL_HOST));
    }

    /** What socialiteproviders/gitlab expects: the instance with exactly one trailing slash. */
    public static function gitlabSocialiteUri(?string $instanceUrl): string
    {
        return rtrim(self::gitlabInstance($instanceUrl), '/').'/';
    }

    /** Resolve the GitLab host, defaulting to the public instance. */
    public static function gitlabInstance(?string $instanceUrl): string
    {
        return $instanceUrl !== null && $instanceUrl !== ''
            ? $instanceUrl
            : self::DEFAULT_GITLAB_INSTANCE;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Loads repository/branch pickers for the Filament forms (onboarding and the
 * repo-profile resource). It centralises the repeated mechanics those forms
 * shared: build a git service from a (platform, token, instance URL) source,
 * cache the dropdown options for 60s to spare the provider API on every
 * Livewire round-trip, and degrade to an empty list on any failure rather than
 * breaking the form. Resolving the source itself (OAuth account vs. PAT,
 * token refresh) stays with the caller — it differs per form.
 */
class RepositoryFetcher
{
    private const TTL_SECONDS = 60;

    public function __construct(private readonly GitServiceFactory $factory) {}

    /**
     * Repository dropdown options, cached under $cacheKey. The key must not
     * contain the token — it is identical per (platform, account, repo).
     *
     * @return array<string, string>
     */
    public function repoOptions(string $platform, string $token, string $instanceUrl, string $cacheKey): array
    {
        return $this->cached($cacheKey, fn (GitServiceContract $service): array => $service->getRepoOptions(), $platform, $token, $instanceUrl);
    }

    /**
     * Branch dropdown options for a repo, cached under $cacheKey.
     *
     * @return array<string, string>
     */
    public function branchOptions(string $platform, string $token, string $instanceUrl, string $repo, string $cacheKey): array
    {
        return $this->cached($cacheKey, fn (GitServiceContract $service): array => $service->getBranchOptions($repo), $platform, $token, $instanceUrl);
    }

    /**
     * The API-reported default branch for a repo, or null on failure. Not
     * cached — it fires once on repo selection.
     */
    public function defaultBranch(string $platform, string $token, string $instanceUrl, string $repo): ?string
    {
        try {
            return $this->factory->forPlatform($platform, $token, $instanceUrl)->getDefaultBranch($repo);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  Closure(GitServiceContract): array<string, string>  $call
     * @return array<string, string>
     */
    private function cached(string $cacheKey, Closure $call, string $platform, string $token, string $instanceUrl): array
    {
        try {
            return Cache::remember(
                $cacheKey,
                now()->addSeconds(self::TTL_SECONDS),
                fn (): array => $call($this->factory->forPlatform($platform, $token, $instanceUrl)),
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\External\Support;

/**
 * Resolves credentials and test-repo coordinates for a single provider.
 *
 * Coordinates (owner, repo, branch, clone URL, instance URL) are hard-coded
 * in tests/External/providers.defaults.php. Per-key environment variables
 * (`<PROVIDER>_TEST_REPO_OWNER`, …) override the defaults — useful for local
 * sandbox testing or self-hosted GitLab.
 *
 * Tokens always come from the environment: `<PROVIDER>_PAT`.
 */
final class ProviderTestConfig
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $instanceUrl,
        public readonly ?string $patToken,
        public readonly string $testRepoOwner,
        public readonly string $testRepo,
        public readonly string $defaultBranch,
        public readonly string $repoCloneUrl,
    ) {}

    public static function fromEnv(string $providerKey): self
    {
        $prefix = strtoupper($providerKey);
        $defaults = self::loadDefaults($providerKey);

        return new self(
            providerKey: $providerKey,
            instanceUrl: self::env("{$prefix}_INSTANCE_URL", $defaults['instanceUrl'] ?? ''),
            patToken: self::envOptional("{$prefix}_PAT"),
            testRepoOwner: self::env("{$prefix}_TEST_REPO_OWNER", $defaults['testRepoOwner'] ?? ''),
            testRepo: self::env("{$prefix}_TEST_REPO", $defaults['testRepo'] ?? ''),
            defaultBranch: self::env("{$prefix}_DEFAULT_BRANCH", $defaults['defaultBranch'] ?? 'main'),
            repoCloneUrl: self::env("{$prefix}_TEST_REPO_CLONE_URL", $defaults['repoCloneUrl'] ?? ''),
        );
    }

    public function isFullyConfigured(): bool
    {
        return $this->patToken !== null
            && $this->testRepoOwner !== ''
            && $this->testRepo !== ''
            && $this->repoCloneUrl !== '';
    }

    /**
     * @return array{instanceUrl?: string, testRepoOwner?: string, testRepo?: string, defaultBranch?: string, repoCloneUrl?: string}
     */
    private static function loadDefaults(string $providerKey): array
    {
        static $cache = null;
        if ($cache === null) {
            $path = __DIR__.'/../providers.defaults.php';
            $cache = is_file($path) ? require $path : [];
        }

        return $cache[$providerKey] ?? [];
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }

    private static function envOptional(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}

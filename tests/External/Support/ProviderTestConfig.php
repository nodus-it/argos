<?php

declare(strict_types=1);

namespace Tests\External\Support;

/**
 * Resolves credentials and test-repo coordinates for a single provider from
 * environment variables. The naming scheme is `<PROVIDER>_<KEY>` —
 * e.g. `GITHUB_PAT`, `GITHUB_TEST_REPO_OWNER`, `GITLAB_INSTANCE_URL`.
 *
 * @phpstan-type AuthMethod array{0: string, 1: string}
 */
final class ProviderTestConfig
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $instanceUrl,
        public readonly ?string $patToken,
        public readonly ?string $oauthToken,
        public readonly string $testRepoOwner,
        public readonly string $testRepo,
        public readonly string $defaultBranch,
        public readonly string $repoCloneUrl,
    ) {}

    public static function fromEnv(string $providerKey): self
    {
        $prefix = strtoupper($providerKey);

        return new self(
            providerKey: $providerKey,
            instanceUrl: self::env("{$prefix}_INSTANCE_URL", self::defaultInstanceUrl($providerKey)),
            patToken: self::envOptional("{$prefix}_PAT"),
            oauthToken: self::envOptional("{$prefix}_OAUTH_TOKEN"),
            testRepoOwner: self::env("{$prefix}_TEST_REPO_OWNER"),
            testRepo: self::env("{$prefix}_TEST_REPO"),
            defaultBranch: self::env("{$prefix}_DEFAULT_BRANCH", 'main'),
            repoCloneUrl: self::env("{$prefix}_TEST_REPO_CLONE_URL"),
        );
    }

    public function hasAnyToken(): bool
    {
        return $this->patToken !== null || $this->oauthToken !== null;
    }

    public function isFullyConfigured(): bool
    {
        return $this->hasAnyToken()
            && $this->testRepoOwner !== ''
            && $this->testRepo !== ''
            && $this->repoCloneUrl !== '';
    }

    /**
     * Tokens to iterate over in the data provider. Only configured ones are returned.
     *
     * @return array<string, AuthMethod>
     */
    public function configuredAuthMethods(): array
    {
        $methods = [];
        if ($this->patToken !== null) {
            $methods['PAT'] = ['pat', $this->patToken];
        }
        if ($this->oauthToken !== null) {
            $methods['OAuth'] = ['oauth', $this->oauthToken];
        }

        return $methods;
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

    private static function defaultInstanceUrl(string $providerKey): string
    {
        return match ($providerKey) {
            'github' => 'https://github.com',
            'gitlab' => 'https://gitlab.com',
            'bitbucket' => 'https://bitbucket.org',
            default => '',
        };
    }
}

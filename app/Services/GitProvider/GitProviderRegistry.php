<?php

declare(strict_types=1);

namespace App\Services\GitProvider;

use App\Services\GitProvider\Contracts\GitProviderContract;
use InvalidArgumentException;

final class GitProviderRegistry
{
    /** @var array<string, callable(string, string): GitProviderContract> */
    private array $providers = [];

    /**
     * @param  callable(string $token, string $instanceUrl): GitProviderContract  $factory
     */
    public function register(string $providerKey, callable $factory): void
    {
        $this->providers[$providerKey] = $factory;
    }

    public function make(string $providerKey, string $token, string $instanceUrl = ''): GitProviderContract
    {
        if (! isset($this->providers[$providerKey])) {
            throw new InvalidArgumentException("Unbekannte Platform: {$providerKey}");
        }

        return ($this->providers[$providerKey])($token, $instanceUrl);
    }

    public function has(string $providerKey): bool
    {
        return isset($this->providers[$providerKey]);
    }
}

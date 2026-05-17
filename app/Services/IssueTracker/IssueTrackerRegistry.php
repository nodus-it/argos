<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\ConnectedAccount;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use InvalidArgumentException;

class IssueTrackerRegistry
{
    /** @var array<string, callable(string, string): IssueTrackerContract> */
    private array $providers = [];

    /**
     * @param  callable(string $token, string $instanceUrl): IssueTrackerContract  $factory
     */
    public function register(string $key, callable $factory): void
    {
        $this->providers[$key] = $factory;
    }

    /**
     * Build an IssueTrackerContract for the given binding, resolving the token
     * from the binding's ConnectedAccount.
     */
    public function make(TaskProviderKind $kind, TaskProviderBinding $binding): IssueTrackerContract
    {
        $key = $kind->value;

        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Kein Issue-Tracker-Provider registriert für: {$key}");
        }

        $account = $binding->connectedAccount;
        $token = $account instanceof ConnectedAccount ? $account->token : '';
        $instanceUrl = $account instanceof ConnectedAccount ? $account->getInstanceUrl() : '';

        return ($this->providers[$key])($token, $instanceUrl);
    }

    public function has(TaskProviderKind $kind): bool
    {
        return isset($this->providers[$kind->value]);
    }
}

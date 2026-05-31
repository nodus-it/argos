<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\ConnectedAccount;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\OAuth\TokenRefresher;
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
        $account = $binding->connectedAccount;
        if ($account instanceof ConnectedAccount) {
            // Refresh an expiring OAuth token before it 401s the poll /
            // write-back / approval calls (GitLab/Bitbucket lapse in ~2h;
            // GitHub OAuth-App and Linear tokens have no expiry → no-op).
            $account = app(TokenRefresher::class)->refreshIfNeeded($account);
        }
        $token = $account instanceof ConnectedAccount ? $account->token : '';
        $instanceUrl = $account instanceof ConnectedAccount ? $account->getInstanceUrl() : '';

        return $this->build($kind->value, $token, $instanceUrl);
    }

    /**
     * Build an IssueTrackerContract straight from a ConnectedAccount, without a
     * persisted binding — used by the setup UI to list selectable references.
     */
    public function makeFromAccount(TaskProviderKind $kind, ConnectedAccount $account): IssueTrackerContract
    {
        $account = app(TokenRefresher::class)->refreshIfNeeded($account);

        return $this->build($kind->value, $account->token, $account->getInstanceUrl());
    }

    public function has(TaskProviderKind $kind): bool
    {
        return isset($this->providers[$kind->value]);
    }

    private function build(string $key, string $token, string $instanceUrl): IssueTrackerContract
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Kein Issue-Tracker-Provider registriert für: {$key}");
        }

        return ($this->providers[$key])($token, $instanceUrl);
    }
}

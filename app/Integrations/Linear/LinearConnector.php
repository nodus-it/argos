<?php

declare(strict_types=1);

namespace App\Integrations\Linear;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

/**
 * Saloon connector for the Linear GraphQL API.
 *
 * Transport layer behind LinearIssueTracker. Linear exposes a single GraphQL
 * endpoint, so every operation is a POST through {@see Requests\GraphQLRequest}.
 * Nothing outside App\Integrations should depend on this class.
 */
class LinearConnector extends Connector
{
    public function __construct(private readonly string $token) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.linear.app';
    }

    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->token);
    }
}

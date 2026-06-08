<?php

declare(strict_types=1);

namespace App\Integrations\OAuth;

use Saloon\Http\Connector;

/**
 * Saloon connector for OAuth token endpoints.
 *
 * Unlike the provider API connectors (api.github.com, …), the OAuth
 * token-grant endpoints live on a different host per provider
 * (github.com/login/oauth, bitbucket.org/site/oauth2, {gitlab}/oauth/token).
 * Rather than one connector per provider, the caller passes the fully resolved
 * token endpoint as the base URL; the request itself is identical across
 * providers. Used by TokenRefresher. Nothing outside App\Integrations should
 * depend on this class.
 */
class OAuthTokenConnector extends Connector
{
    public function __construct(private readonly string $tokenEndpoint) {}

    public function resolveBaseUrl(): string
    {
        return $this->tokenEndpoint;
    }
}

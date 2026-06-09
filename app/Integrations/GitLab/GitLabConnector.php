<?php

declare(strict_types=1);

namespace App\Integrations\GitLab;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

/**
 * Saloon connector for the GitLab REST API (v4).
 *
 * Transport layer behind GitLabGitService and GitLabIssueTracker. The base URL
 * is derived from the instance URL so the same connector serves gitlab.com and
 * self-hosted instances. Nothing outside App\Integrations should depend on this
 * class; the domain Contracts remain the public surface.
 */
class GitLabConnector extends Connector
{
    public function __construct(
        private readonly string $token,
        private readonly string $instanceUrl = 'https://gitlab.com',
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->instanceUrl, '/').'/api/v4';
    }

    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->token);
    }
}

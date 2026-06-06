<?php

declare(strict_types=1);

namespace App\Integrations\GitHub;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

/**
 * Saloon connector for the GitHub REST API.
 *
 * Transport layer behind GitHubGitService and the GitHub issue tracker — it
 * centralises base URL, auth and the GitHub versioning headers that were
 * previously duplicated in each service's private http() builder. Nothing
 * outside App\Integrations should depend on this class; the domain Contracts
 * remain the public surface.
 */
class GitHubConnector extends Connector
{
    public function __construct(private readonly string $token) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.github.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }

    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->token);
    }
}

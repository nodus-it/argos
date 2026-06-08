<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\BasicAuthenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

/**
 * Saloon connector for the Bitbucket Cloud API (2.0).
 *
 * Transport layer behind BitbucketGitService and BitbucketIssueTracker. It
 * absorbs Bitbucket's dual auth: a token containing a colon is Basic auth
 * ("email:api_token" or a legacy App Password); without a colon it is a
 * Bearer token (Repository Access Token or OAuth). Nothing outside
 * App\Integrations should depend on this class.
 */
class BitbucketConnector extends Connector
{
    private readonly bool $isOAuth;

    private readonly string $username;

    private readonly string $appPassword;

    public function __construct(private readonly string $token)
    {
        if (str_contains($token, ':')) {
            [$this->username, $this->appPassword] = explode(':', $token, 2);
            $this->isOAuth = false;
        } else {
            $this->username = '';
            $this->appPassword = '';
            $this->isOAuth = true;
        }
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.bitbucket.org/2.0';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['Accept' => 'application/json'];
    }

    protected function defaultAuth(): ?Authenticator
    {
        return $this->isOAuth
            ? new TokenAuthenticator($this->token)
            : new BasicAuthenticator($this->username, $this->appPassword);
    }
}

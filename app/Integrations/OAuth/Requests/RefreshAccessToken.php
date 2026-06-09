<?php

declare(strict_types=1);

namespace App\Integrations\OAuth\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

/**
 * POST {token endpoint} — the standard OAuth `grant_type=refresh_token` flow.
 * The endpoint is supplied as the connector's base URL, so this request only
 * carries the form body. Provider-agnostic: GitHub, GitLab and Bitbucket all
 * accept the same parameters.
 */
class RefreshAccessToken extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $refreshToken,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function resolveEndpoint(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['Accept' => 'application/json'];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\Linear\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

/**
 * POST /oauth/token — exchanges the authorization code for an access token
 * during the Linear OAuth callback. Unauthenticated (the client secret in the
 * body is the credential), so it runs through a token-less LinearConnector.
 */
class OAuthTokenExchange extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $code,
        private readonly string $redirectUri,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/oauth/token';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $this->code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];
    }
}

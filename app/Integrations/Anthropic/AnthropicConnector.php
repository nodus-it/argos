<?php

declare(strict_types=1);

namespace App\Integrations\Anthropic;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

/**
 * Saloon connector for the Anthropic API.
 *
 * Transport layer behind AnthropicTokenValidator (token validation) and the
 * usage sidebar. Centralises base URL, bearer auth, the shared OAuth beta
 * header / User-Agent and the short 5s timeout that these read-only,
 * UI-facing probes need. Nothing outside App\Integrations should depend on
 * this class.
 */
class AnthropicConnector extends Connector
{
    public function __construct(private readonly string $token) {}

    public function resolveBaseUrl(): string
    {
        return 'https://api.anthropic.com';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'anthropic-beta' => 'oauth-2025-04-20',
            'User-Agent' => 'claude-code/2.0.31',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        // These calls block UI rendering — fail fast rather than hang.
        return ['timeout' => 5];
    }

    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->token);
    }
}

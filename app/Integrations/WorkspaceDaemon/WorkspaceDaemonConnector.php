<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

/**
 * Transport to one workspace daemon (`daemon/`) over the internal argos_ws
 * network. Pure transport: callers pass the base URL and bearer token.
 */
class WorkspaceDaemonConnector extends Connector
{
    use AcceptsJson;
    use AlwaysThrowOnErrors;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator($this->token);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['timeout' => $this->timeoutSeconds, 'connect_timeout' => 3];
    }
}

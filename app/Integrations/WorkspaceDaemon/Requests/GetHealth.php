<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /health — daemon/SDK/claude versions and running tasks. */
class GetHealth extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/health';
    }
}

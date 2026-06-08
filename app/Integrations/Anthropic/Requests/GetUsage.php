<?php

declare(strict_types=1);

namespace App\Integrations\Anthropic\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/oauth/usage — five-hour and seven-day utilisation for the Claude
 * OAuth token shown in the usage sidebar. Requires the user:profile scope; a
 * `permission_error` body means the token lacks it.
 */
class GetUsage extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/oauth/usage';
    }
}

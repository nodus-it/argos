<?php

declare(strict_types=1);

namespace App\Integrations\Anthropic\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /v1/models — cheapest authenticated endpoint, used purely to confirm a
 * Claude OAuth token is accepted. A 401/403 means rejected; any other failure
 * is treated as "unreachable" by the caller.
 */
class ValidateToken extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v1/models';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['anthropic-version' => '2023-06-01'];
    }
}

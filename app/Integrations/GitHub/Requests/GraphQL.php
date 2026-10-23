<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /graphql — a GitHub GraphQL query or mutation. Used for operations the
 * REST API does not expose (marking a draft ready, native auto-merge). GraphQL
 * reports most failures as HTTP 200 with an `errors` array, so callers inspect
 * the body rather than relying on the status code alone.
 */
class GraphQL extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        private readonly string $gqlQuery,
        private readonly array $variables = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/graphql';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $payload = ['query' => $this->gqlQuery];

        if ($this->variables !== []) {
            $payload['variables'] = $this->variables;
        }

        return $payload;
    }
}

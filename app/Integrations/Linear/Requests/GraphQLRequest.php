<?php

declare(strict_types=1);

namespace App\Integrations\Linear\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /graphql — a single Linear GraphQL query or mutation. Linear exposes one
 * endpoint, so every tracker operation is expressed through this request.
 */
class GraphQLRequest extends Request implements HasBody
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

        // Linear rejects `variables: []` (an empty PHP array encodes to a JSON
        // array, not an object) — omit the key entirely for variable-less queries.
        if ($this->variables !== []) {
            $payload['variables'] = $this->variables;
        }

        return $payload;
    }
}

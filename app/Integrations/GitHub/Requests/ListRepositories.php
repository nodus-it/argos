<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /user/repos — repositories the authenticated user can access.
 */
class ListRepositories extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/user/repos';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'per_page' => 100,
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ];
    }
}

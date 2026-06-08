<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repositories — repositories the authenticated user is a member of,
 * across all workspaces. Used by the issue tracker's reference list.
 */
class ListRepositories extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/repositories';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'role' => 'member',
            'pagelen' => 100,
            'sort' => '-updated_on',
        ];
    }
}

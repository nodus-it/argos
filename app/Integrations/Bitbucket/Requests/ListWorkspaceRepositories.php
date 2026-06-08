<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repositories/{workspace} — repositories in a workspace the user is a
 * member of.
 */
class ListWorkspaceRepositories extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $workspace) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['role' => 'member', 'pagelen' => 100];
    }
}

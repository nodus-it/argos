<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /workspaces/{workspace}/members — the workspace's members. Bitbucket
 * dropped username lookups (GDPR), so a nickname is resolved to a uuid here.
 */
class ListWorkspaceMembers extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $workspace,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/workspaces/{$this->workspace}/members";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['pagelen' => 100];
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repositories/{owner}/{repo}/refs/branches — branches of a repository.
 */
class ListBranches extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->repo}/refs/branches";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['pagelen' => 100];
    }
}

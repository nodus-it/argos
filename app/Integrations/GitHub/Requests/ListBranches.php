<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repos/{owner}/{repo}/branches — branches of a repository.
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
        return "/repos/{$this->owner}/{$this->repo}/branches";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['per_page' => 100];
    }
}

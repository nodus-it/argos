<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /repositories/{workspace}/{repo}/pullrequests/{id}/statuses — build statuses of the PR head commit. */
class ListPullRequestStatuses extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->repo}/pullrequests/{$this->id}/statuses";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['pagelen' => 100];
    }
}

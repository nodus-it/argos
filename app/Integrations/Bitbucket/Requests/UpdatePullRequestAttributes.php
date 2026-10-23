<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PUT /repositories/{workspace}/{repo}/pullrequests/{id} — partial update of
 * arbitrary pull request attributes (e.g. `draft`).
 */
class UpdatePullRequestAttributes extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $pullRequestId,
        private readonly array $attributes,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->repo}/pullrequests/{$this->pullRequestId}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->attributes;
    }
}

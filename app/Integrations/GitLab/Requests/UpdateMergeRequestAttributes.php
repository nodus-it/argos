<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PUT /projects/{path}/merge_requests/{iid} — partial update of arbitrary
 * merge request attributes (e.g. the title when removing the draft marker).
 */
class UpdateMergeRequestAttributes extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $mergeRequestId,
        private readonly array $attributes,
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->repo}");

        return "/projects/{$path}/merge_requests/{$this->mergeRequestId}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->attributes;
    }
}

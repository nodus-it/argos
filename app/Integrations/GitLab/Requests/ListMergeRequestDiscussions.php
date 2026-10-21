<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /projects/{path}/merge_requests/{iid}/discussions — threads (general and diff notes) of a merge request. */
class ListMergeRequestDiscussions extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $mergeRequestId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/projects/".urlencode("{$this->owner}/{$this->repo}")."/merge_requests/{$this->mergeRequestId}/discussions";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['per_page' => 100];
    }
}

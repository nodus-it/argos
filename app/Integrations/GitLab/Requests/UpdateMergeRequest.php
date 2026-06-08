<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PUT /projects/{path}/merge_requests/{iid} — update a merge request's
 * title and description.
 */
class UpdateMergeRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $mergeRequestId,
        private readonly string $title,
        private readonly string $description,
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
        return [
            'title' => $this->title,
            'description' => $this->description,
        ];
    }
}

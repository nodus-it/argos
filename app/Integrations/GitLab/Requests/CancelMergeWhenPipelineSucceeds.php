<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * POST /projects/{path}/merge_requests/{iid}/cancel_merge_when_pipeline_succeeds
 * — clear a pending auto-merge.
 */
class CancelMergeWhenPipelineSucceeds extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $mergeRequestId,
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->repo}");

        return "/projects/{$path}/merge_requests/{$this->mergeRequestId}/cancel_merge_when_pipeline_succeeds";
    }
}

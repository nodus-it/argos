<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /projects/{path}/merge_requests/{iid}/approvals — approval state of a merge request. */
class GetMergeRequestApprovals extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $mergeRequestId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/projects/".urlencode("{$this->owner}/{$this->repo}")."/merge_requests/{$this->mergeRequestId}/approvals";
    }
}

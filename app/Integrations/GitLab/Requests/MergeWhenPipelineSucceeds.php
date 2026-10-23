<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PUT /projects/{path}/merge_requests/{iid}/merge with
 * `merge_when_pipeline_succeeds` (`auto_merge` on GitLab >= 17.11) — set the
 * MR to merge once its head pipeline succeeds. Beware: without a running
 * pipeline GitLab merges immediately, so callers check the pipeline first.
 */
class MergeWhenPipelineSucceeds extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $mergeRequestId,
        private readonly ?string $sha = null,
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->repo}");

        return "/projects/{$path}/merge_requests/{$this->mergeRequestId}/merge";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'merge_when_pipeline_succeeds' => true,
            'auto_merge' => true,
        ];

        // Guards against merging a head that moved on since we looked at it.
        if ($this->sha !== null) {
            $body['sha'] = $this->sha;
        }

        return $body;
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /projects/{path}/jobs/{id}/trace — raw log (trace) of a CI job. */
class GetJobTrace extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $jobId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/projects/'.urlencode("{$this->owner}/{$this->repo}")."/jobs/{$this->jobId}/trace";
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /projects/{path}/pipelines/{id}/jobs?scope=failed — failed jobs of a pipeline. */
class ListFailedPipelineJobs extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $pipelineId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/projects/".urlencode("{$this->owner}/{$this->repo}")."/pipelines/{$this->pipelineId}/jobs";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['scope' => 'failed', 'per_page' => 100];
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /repos/{owner}/{repo}/actions/jobs/{id}/logs — plain-text log of a GitHub Actions job (redirects to a signed download URL). */
class GetActionsJobLogs extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $jobId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->repo}/actions/jobs/{$this->jobId}/logs";
    }
}

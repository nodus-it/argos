<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repositories/{owner}/{repo}/issues/{id}/comments — an issue's comments.
 */
class GetIssueComments extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $issueNumber,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->project}/issues/{$this->issueNumber}/comments";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['pagelen' => 100];
    }
}

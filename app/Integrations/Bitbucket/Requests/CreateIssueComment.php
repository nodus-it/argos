<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /repositories/{owner}/{repo}/issues/{id}/comments — comment on an issue.
 * Bitbucket nests the body under content.raw.
 */
class CreateIssueComment extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $issueNumber,
        private readonly string $comment,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->project}/issues/{$this->issueNumber}/comments";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['content' => ['raw' => $this->comment]];
    }
}

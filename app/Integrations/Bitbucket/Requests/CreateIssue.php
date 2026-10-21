<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /repositories/{owner}/{repo}/issues — open a new issue.
 * Bitbucket nests the body under content.raw.
 */
class CreateIssue extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly string $title,
        private readonly string $issueBody,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->project}/issues";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['title' => $this->title, 'content' => ['raw' => $this->issueBody]];
    }
}

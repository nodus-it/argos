<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PUT /repositories/{owner}/{repo}/issues/{id} — set the issue's assignee
 * (addressed by the user's uuid).
 */
class UpdateIssueAssignee extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $issueNumber,
        private readonly string $userUuid,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->project}/issues/{$this->issueNumber}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['assignee' => ['uuid' => $this->userUuid]];
    }
}

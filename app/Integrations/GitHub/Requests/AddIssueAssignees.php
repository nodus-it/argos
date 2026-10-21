<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /repos/{owner}/{project}/issues/{number}/assignees — add assignees to
 * an issue. GitHub silently drops logins that can't be assigned.
 */
class AddIssueAssignees extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $assignees
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $issueNumber,
        private readonly array $assignees,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->project}/issues/{$this->issueNumber}/assignees";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['assignees' => $this->assignees];
    }
}

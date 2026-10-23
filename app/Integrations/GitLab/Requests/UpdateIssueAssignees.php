<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PUT /projects/{path}/issues/{iid} — replace the issue's assignees.
 */
class UpdateIssueAssignees extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  list<int>  $assigneeIds
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $issueNumber,
        private readonly array $assigneeIds,
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->project}");

        return "/projects/{$path}/issues/{$this->issueNumber}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['assignee_ids' => $this->assigneeIds];
    }
}

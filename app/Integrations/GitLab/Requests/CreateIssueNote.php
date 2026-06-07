<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /projects/{path}/issues/{iid}/notes — comment on an issue.
 */
class CreateIssueNote extends Request implements HasBody
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
        $path = urlencode("{$this->owner}/{$this->project}");

        return "/projects/{$path}/issues/{$this->issueNumber}/notes";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['body' => $this->comment];
    }
}

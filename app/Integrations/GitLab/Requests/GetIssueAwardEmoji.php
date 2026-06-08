<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /projects/{path}/issues/{iid}/award_emoji — an issue's award emoji
 * (reactions). Requires a GitLab plan that supports them; callers treat a
 * 404/403 as empty.
 */
class GetIssueAwardEmoji extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $issueNumber,
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->project}");

        return "/projects/{$path}/issues/{$this->issueNumber}/award_emoji";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['per_page' => 100];
    }
}

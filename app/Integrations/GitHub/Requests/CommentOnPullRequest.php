<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /repos/{owner}/{repo}/issues/{number}/comments — comment on a PR.
 * GitHub treats PRs as issues, so PR comments use the issues endpoint with
 * the PR number as issue id.
 */
class CommentOnPullRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $pullRequestId,
        private readonly string $comment,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->repo}/issues/{$this->pullRequestId}/comments";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['body' => $this->comment];
    }
}

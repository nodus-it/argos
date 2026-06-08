<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PATCH /repos/{owner}/{repo}/pulls/{number} — update a PR's title and body.
 */
class UpdatePullRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $pullRequestId,
        private readonly string $title,
        private readonly string $description,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->repo}/pulls/{$this->pullRequestId}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->description,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * PUT /repositories/{owner}/{repo}/pullrequests/{id} — update a pull request's
 * title and description.
 */
class UpdatePullRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $pullRequestId,
        private readonly string $title,
        private readonly string $description,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->repo}/pullrequests/{$this->pullRequestId}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
        ];
    }
}

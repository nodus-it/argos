<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /repos/{owner}/{repo}/pulls/{number} — state and merge status of a PR. */
class GetPullRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly int|string $number,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->repo}/pulls/{$this->number}";
    }
}

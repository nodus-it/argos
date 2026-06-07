<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /projects/{path}/members/all/{userId} — a user's membership (incl.
 * inherited), used to read their access level.
 */
class GetProjectMember extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly int|string $userId,
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->project}");

        return "/projects/{$path}/members/all/{$this->userId}";
    }
}

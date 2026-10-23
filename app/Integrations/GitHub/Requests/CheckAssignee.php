<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repos/{owner}/{project}/assignees/{login} — 204 when the user can be
 * assigned to issues in the repository, 404 otherwise.
 */
class CheckAssignee extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly string $login,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->project}/assignees/{$this->login}";
    }
}

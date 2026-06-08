<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repos/{owner}/{project}/collaborators/{login}/permission —
 * a user's permission level on the repository.
 */
class GetCollaboratorPermission extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly string $login,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->project}/collaborators/{$this->login}/permission";
    }
}

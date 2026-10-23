<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /users?username={username} — look a user up by username. Returns a
 * list with zero or one entry.
 */
class FindUserByUsername extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $username,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['username' => $this->username];
    }
}

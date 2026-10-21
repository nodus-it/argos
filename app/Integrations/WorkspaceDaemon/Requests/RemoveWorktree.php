<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** DELETE /worktrees/{slug} — remove a finished task's worktree and local branch. */
class RemoveWorktree extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(private readonly string $worktree) {}

    public function resolveEndpoint(): string
    {
        return '/worktrees/'.rawurlencode($this->worktree);
    }
}

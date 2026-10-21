<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /worktrees/{slug}/git/status — branch, ahead/behind and changed files. */
class GitStatus extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $worktree) {}

    public function resolveEndpoint(): string
    {
        return '/worktrees/'.rawurlencode($this->worktree).'/git/status';
    }
}

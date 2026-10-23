<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/**
 * POST /worktrees/{slug}/prepare — create the task's worktree, write its
 * .env and run the setup commands (background; progress arrives as events).
 */
class PrepareWorktree extends DaemonRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly string $worktree, array $payload = [])
    {
        parent::__construct($payload);
    }

    public function resolveEndpoint(): string
    {
        return '/worktrees/'.rawurlencode($this->worktree).'/prepare';
    }
}

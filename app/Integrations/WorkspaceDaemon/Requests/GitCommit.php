<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /worktrees/{slug}/git/commit — stage everything and commit. */
class GitCommit extends DaemonRequest
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
        return '/worktrees/'.rawurlencode($this->worktree).'/git/commit';
    }
}

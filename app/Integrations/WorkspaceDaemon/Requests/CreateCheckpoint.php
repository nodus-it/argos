<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /worktrees/{slug}/checkpoints — take a named checkpoint of a task. */
class CreateCheckpoint extends DaemonRequest
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
        return '/worktrees/'.rawurlencode($this->worktree).'/checkpoints';
    }
}

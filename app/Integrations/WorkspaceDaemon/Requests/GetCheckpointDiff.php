<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /worktrees/{slug}/checkpoints/diff — what restoring a checkpoint would undo. */
class GetCheckpointDiff extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $worktree, private readonly string $ref) {}

    public function resolveEndpoint(): string
    {
        return '/worktrees/'.rawurlencode($this->worktree).'/checkpoints/diff';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['ref' => $this->ref];
    }
}

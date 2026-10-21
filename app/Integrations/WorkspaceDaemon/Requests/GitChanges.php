<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /worktrees/{slug}/git/changes?base=<ref> — changed files with line counts (no diff text). */
class GitChanges extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $worktree, private readonly string $base) {}

    public function resolveEndpoint(): string
    {
        return '/worktrees/'.rawurlencode($this->worktree).'/git/changes';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['base' => $this->base];
    }
}

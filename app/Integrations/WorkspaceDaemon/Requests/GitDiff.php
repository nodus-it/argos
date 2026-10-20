<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /git/diff?base=<ref> — everything changed since the merge base. */
class GitDiff extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $base) {}

    public function resolveEndpoint(): string
    {
        return '/git/diff';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['base' => $this->base];
    }
}

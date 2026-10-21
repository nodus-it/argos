<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /git/diff?base=<ref>[&path=] — everything (or one file) changed since the merge base. */
class GitDiff extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $base, private readonly ?string $path = null) {}

    public function resolveEndpoint(): string
    {
        return '/git/diff';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return array_filter(['base' => $this->base, 'path' => $this->path], fn (?string $v): bool => $v !== null);
    }
}

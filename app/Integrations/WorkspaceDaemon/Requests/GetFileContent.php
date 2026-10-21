<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /files/content?path= — a working-tree file for the review (expanding unchanged lines). */
class GetFileContent extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $path) {}

    public function resolveEndpoint(): string
    {
        return '/files/content';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['path' => $this->path];
    }
}

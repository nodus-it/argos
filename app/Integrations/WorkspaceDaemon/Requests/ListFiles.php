<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /files?q= — fuzzy file search for @ mentions. */
class ListFiles extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $search, private readonly int $limit = 20) {}

    public function resolveEndpoint(): string
    {
        return '/files';
    }

    /**
     * @return array<string, string|int>
     */
    protected function defaultQuery(): array
    {
        return ['q' => $this->search, 'limit' => $this->limit];
    }
}

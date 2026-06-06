<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repos/{owner}/{repo}/contents/{path} — raw file metadata at a ref.
 * A 404 (file absent) is handled by the caller, not thrown here.
 */
class GetFileContents extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $path,
        private readonly string $ref,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repos/{$this->owner}/{$this->repo}/contents/".ltrim($this->path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['ref' => $this->ref];
    }
}

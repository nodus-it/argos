<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repositories/{owner}/{repo}/src/{ref}/{path} — raw file body at a ref.
 * A 404 (file absent) is handled by the caller, not thrown here.
 */
class GetSourceFile extends Request
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
        return "/repositories/{$this->owner}/{$this->repo}/src/{$this->ref}/".ltrim($this->path, '/');
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /projects/{path}/repository/files/{file}/raw — raw file body at a ref.
 * A 404 (file absent) is handled by the caller, not thrown here.
 */
class GetRawFile extends Request
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
        $projectPath = urlencode("{$this->owner}/{$this->repo}");
        $filePath = rawurlencode(ltrim($this->path, '/'));

        return "/projects/{$projectPath}/repository/files/{$filePath}/raw";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['ref' => $this->ref];
    }
}

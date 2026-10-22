<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** GET /repositories/{workspace}/{repo}/pullrequests?q=… — the open PR of a branch. */
class FindOpenPullRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $sourceBranch,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->owner}/{$this->repo}/pullrequests";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        $branch = str_replace(['\\', '"'], ['\\\\', '\\"'], $this->sourceBranch);

        return ['q' => "source.branch.name=\"{$branch}\" AND state=\"OPEN\"", 'pagelen' => 1];
    }
}

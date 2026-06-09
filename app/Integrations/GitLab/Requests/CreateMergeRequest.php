<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /projects/{path}/merge_requests — open a merge request.
 */
class CreateMergeRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $title,
        private readonly string $description,
        private readonly string $headBranch,
        private readonly string $baseBranch,
        private readonly array $options = [],
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->repo}");

        return "/projects/{$path}/merge_requests";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'source_branch' => $this->headBranch,
            'target_branch' => $this->baseBranch,
            ...$this->options,
        ];
    }
}

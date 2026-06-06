<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /repos/{owner}/{repo}/pulls — open a pull request.
 */
class CreatePullRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $options  e.g. draft, reviewers, labels
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
        return "/repos/{$this->owner}/{$this->repo}/pulls";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->description,
            'head' => $this->headBranch,
            'base' => $this->baseBranch,
            ...$this->options,
        ];
    }
}

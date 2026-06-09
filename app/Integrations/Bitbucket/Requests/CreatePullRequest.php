<?php

declare(strict_types=1);

namespace App\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /repositories/{owner}/{repo}/pullrequests — open a pull request.
 */
class CreatePullRequest extends Request implements HasBody
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
        return "/repositories/{$this->owner}/{$this->repo}/pullrequests";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'source' => ['branch' => ['name' => $this->headBranch]],
            'destination' => ['branch' => ['name' => $this->baseBranch]],
            ...$this->options,
        ];
    }
}

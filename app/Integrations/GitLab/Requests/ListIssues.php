<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /projects/{path}/issues — one page of issues. Pagination is page-based,
 * driven by GitLab's X-Next-Page response header.
 */
class ListIssues extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $owner,
        private readonly string $project,
        private readonly string $state,
        private readonly ?int $page = null,
    ) {}

    public function resolveEndpoint(): string
    {
        $path = urlencode("{$this->owner}/{$this->project}");

        return "/projects/{$path}/issues";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        $query = ['per_page' => 100, 'state' => $this->state];

        if ($this->page !== null) {
            $query['page'] = $this->page;
        }

        return $query;
    }
}

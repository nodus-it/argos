<?php

declare(strict_types=1);

namespace App\Integrations\GitHub\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /repos/{owner}/{project}/issues — one page of issues.
 *
 * Pagination follows GitHub's Link header, but only the page number is taken
 * from it; the caller re-issues this request with $page so the endpoint stays
 * relative (no base-URL override / SSRF surface).
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
        return "/repos/{$this->owner}/{$this->project}/issues";
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

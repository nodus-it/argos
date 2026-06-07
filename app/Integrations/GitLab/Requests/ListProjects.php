<?php

declare(strict_types=1);

namespace App\Integrations\GitLab\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /projects — projects the authenticated user is a member of.
 */
class ListProjects extends Request
{
    protected Method $method = Method::GET;

    /**
     * @param  bool  $simple  request GitLab's trimmed-down "simple" payload
     *                        (used by the issue tracker's reference list)
     */
    public function __construct(private readonly bool $simple = false) {}

    public function resolveEndpoint(): string
    {
        return '/projects';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        $query = [
            'membership' => true,
            'per_page' => 100,
            'order_by' => 'last_activity_at',
        ];

        if ($this->simple) {
            $query['simple'] = true;
        }

        return $query;
    }
}

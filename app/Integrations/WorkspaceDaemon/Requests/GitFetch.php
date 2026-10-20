<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /git/fetch — fetch origin. */
class GitFetch extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/git/fetch';
    }
}

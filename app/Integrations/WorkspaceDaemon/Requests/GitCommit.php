<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /git/commit — stage everything and commit. */
class GitCommit extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/git/commit';
    }
}

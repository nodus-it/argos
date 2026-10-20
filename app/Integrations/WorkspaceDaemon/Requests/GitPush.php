<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /git/push — push the workspace branch. */
class GitPush extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/git/push';
    }
}

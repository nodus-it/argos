<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /workspace/init — clone and check out the workspace branch (idempotent). */
class InitWorkspace extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/workspace/init';
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /oneshot — a single tool-less prompt (e.g. a commit message). */
class RunOneshot extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/oneshot';
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /gates/run — start a quality-gate run (results arrive as events). */
class RunGates extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/gates/run';
    }
}

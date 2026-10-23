<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /models — the models an agent's subscription offers (no message is sent). */
class ListModels extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/models';
    }
}

<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /checkpoints — take a named checkpoint of a task. */
class CreateCheckpoint extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/checkpoints';
    }
}

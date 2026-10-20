<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/** POST /checkpoints/restore — reset the working tree to a checkpoint. */
class RestoreCheckpoint extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/checkpoints/restore';
    }
}

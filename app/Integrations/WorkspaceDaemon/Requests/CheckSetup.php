<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon\Requests;

/**
 * POST /setup/check — run the setup steps of a branch in a throwaway
 * worktree and answer with the outcome (setup check of a workspace setup).
 */
class CheckSetup extends DaemonRequest
{
    public function resolveEndpoint(): string
    {
        return '/setup/check';
    }
}

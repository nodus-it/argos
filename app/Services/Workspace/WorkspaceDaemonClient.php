<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Integrations\WorkspaceDaemon\WorkspaceDaemonApi;
use App\Integrations\WorkspaceDaemon\WorkspaceDaemonTransport;
use App\Models\Workspace;

/**
 * Resolves the daemon endpoint of a workspace (container name on the
 * argos_ws network + bearer token) and hands out the transport for it.
 */
class WorkspaceDaemonClient
{
    public function baseUrl(Workspace $workspace): string
    {
        return 'http://'.$workspace->containerName().':'.(int) config('argos.workspaces.daemon_port', 7777);
    }

    public function for(Workspace $workspace, int $timeoutSeconds = 15): WorkspaceDaemonTransport
    {
        return new WorkspaceDaemonApi($this->baseUrl($workspace), $workspace->daemon_token, $timeoutSeconds);
    }
}

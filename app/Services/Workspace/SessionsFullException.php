<?php

declare(strict_types=1);

namespace App\Services\Workspace;

/**
 * No free session right now: the workspace's `max_sessions` or the agent's
 * `max_concurrent_sessions` is reached. Unlike a limited or missing agent this
 * passes — the task waits in the session queue (see SessionQueue).
 */
class SessionsFullException extends AgentBusyException
{
    /** 'workspace' or 'agent': which limit is reached. */
    public string $scope = 'agent';
}

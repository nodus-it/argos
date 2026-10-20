<?php

declare(strict_types=1);

namespace App\Services\Workspace;

/** The agent has no free session slot (or is usage-limited). */
class AgentBusyException extends WorkspaceException {}

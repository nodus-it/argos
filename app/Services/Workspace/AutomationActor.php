<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\ApiClient;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who an automatic run acts for. Auto-tasks always run as a person — their
 * agent (subscription) does the work and they own the workspace: a signed-in
 * user (OAuth/MCP) acts for themselves, a project token for the project's
 * automation user, an API client for the user it runs as.
 */
final class AutomationActor
{
    public static function for(mixed $principal): ?User
    {
        return match (true) {
            $principal instanceof User => $principal,
            $principal instanceof Workspace => $principal->automationUser,
            $principal instanceof ApiClient => $principal->runAsUser,
            default => null,
        };
    }
}

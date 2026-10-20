<?php

declare(strict_types=1);

namespace App\Events\Workspace;

use App\Events\DomainEvent;
use App\Models\Workspace;

/** A workspace was created; provisioning is queued. */
final class WorkspaceCreated extends DomainEvent
{
    public function __construct(public readonly Workspace $workspace)
    {
        parent::__construct();
    }
}

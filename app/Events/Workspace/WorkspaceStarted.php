<?php

declare(strict_types=1);

namespace App\Events\Workspace;

use App\Events\DomainEvent;
use App\Models\Workspace;

/** A workspace container is up and its daemon healthy. */
final class WorkspaceStarted extends DomainEvent
{
    public function __construct(public readonly Workspace $workspace)
    {
        parent::__construct();
    }
}

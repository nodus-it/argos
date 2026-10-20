<?php

declare(strict_types=1);

namespace App\Events\Workspace;

use App\Events\DomainEvent;
use App\Models\Workspace;

/** A workspace container was stopped (volumes kept). */
final class WorkspaceStopped extends DomainEvent
{
    public function __construct(public readonly Workspace $workspace)
    {
        parent::__construct();
    }
}

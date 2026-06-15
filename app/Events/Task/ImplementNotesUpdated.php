<?php

declare(strict_types=1);

namespace App\Events\Task;

use App\Events\DomainEvent;
use App\Models\Task;

final class ImplementNotesUpdated extends DomainEvent
{
    public function __construct(public readonly Task $task)
    {
        parent::__construct();
    }
}

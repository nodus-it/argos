<?php

declare(strict_types=1);

namespace App\Events\Task;

use App\Enums\Phase;
use App\Events\DomainEvent;
use App\Models\Task;

final class PhaseStarted extends DomainEvent
{
    public function __construct(
        public readonly Task $task,
        public readonly Phase $phase,
    ) {
        parent::__construct();
    }
}

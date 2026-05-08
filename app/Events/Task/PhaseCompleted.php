<?php

declare(strict_types=1);

namespace App\Events\Task;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Models\Task;

final class PhaseCompleted
{
    public function __construct(
        public readonly Task $task,
        public readonly Phase $phase,
        public readonly PhaseStatus $status,
    ) {}
}

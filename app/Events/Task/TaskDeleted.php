<?php

declare(strict_types=1);

namespace App\Events\Task;

use App\Models\Task;

final class TaskDeleted
{
    public function __construct(public readonly Task $task) {}
}

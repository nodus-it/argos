<?php

declare(strict_types=1);

namespace App\Enums;

/** An ordinary task, or the setup task that prepares the workspace's environment. */
enum TaskKind: string
{
    case Task = 'task';
    case Setup = 'setup';

    public function label(): string
    {
        return __('enums.task_kind.'.$this->value);
    }
}

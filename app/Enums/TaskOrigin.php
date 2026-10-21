<?php

declare(strict_types=1);

namespace App\Enums;

/** Who started a task — automatic cleanup only touches non-manual ones. */
enum TaskOrigin: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Mcp = 'mcp';
    case Issue = 'issue';

    public function label(): string
    {
        return __('enums.task_origin.'.$this->value);
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/** Who started a workspace — automatic cleanup only touches non-manual ones. */
enum WorkspaceOrigin: string
{
    case Manual = 'manual';
    case Api = 'api';
    case Mcp = 'mcp';
    case Issue = 'issue';

    public function label(): string
    {
        return __('enums.workspace_origin.'.$this->value);
    }
}

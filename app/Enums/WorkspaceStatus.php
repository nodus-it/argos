<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of a workspace container (see WorkspaceService). */
enum WorkspaceStatus: string
{
    case Provisioning = 'provisioning';
    case Starting = 'starting';
    case Running = 'running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.workspace_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Provisioning => 'info',
            self::Starting => 'info',
            self::Running => 'success',
            self::Stopping => 'warning',
            self::Stopped => 'gray',
            self::Failed => 'danger',
        };
    }
}

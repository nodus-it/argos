<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a workspace's setup stands: something is missing (stack, preview
 * contract, setup steps), complete but never tested, the setup task works on
 * it or is being tested, tested and in use, failed, or no Claude subscription.
 */
enum WorkspaceSetupStatus: string
{
    case Needed = 'needed';
    case Untested = 'untested';
    case Running = 'running';
    case Testing = 'testing';
    case Ready = 'ready';
    case Failed = 'failed';
    case NeedsAgent = 'needs_agent';

    public function label(): string
    {
        return __('enums.workspace_setup_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Running, self::Testing => 'info',
            self::Failed => 'danger',
            self::Needed, self::Untested, self::NeedsAgent => 'warning',
        };
    }
}

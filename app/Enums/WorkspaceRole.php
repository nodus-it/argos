<?php

declare(strict_types=1);

namespace App\Enums;

/** A member's role in a workspace: owners manage members, editors prompt and approve, viewers only read. */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return __('enums.workspace_role.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Owner => 'primary',
            self::Editor => 'info',
            self::Viewer => 'gray',
        };
    }

    /** Owners and editors may prompt, interrupt and approve. */
    public function canOperate(): bool
    {
        return $this !== self::Viewer;
    }
}

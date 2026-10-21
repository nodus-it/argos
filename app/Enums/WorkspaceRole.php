<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A person's role in a workspace. Admins manage settings, secrets, members
 * and the setup; members create tasks; viewers read (and work in tasks they
 * are invited to). What a role may do is its capability set.
 */
enum WorkspaceRole: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return __('enums.workspace_role.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'primary',
            self::Member => 'info',
            self::Viewer => 'gray',
        };
    }

    /**
     * Capabilities the role grants across the whole workspace. Task-level
     * capabilities additionally come from owning or collaborating on a task
     * (see TaskAccess). Fixed today; configurable roles plug in here.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Admin => Capability::cases(),
            self::Member => [Capability::TaskCreate, Capability::WorkspaceOperate, Capability::WorkspaceEditor],
            self::Viewer => [],
        };
    }

    public function grants(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}

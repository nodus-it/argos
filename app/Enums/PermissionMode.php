<?php

declare(strict_types=1);

namespace App\Enums;

/** Claude Code permission modes a thread can run in. Values match the SDK. */
enum PermissionMode: string
{
    case Default = 'default';
    case AcceptEdits = 'acceptEdits';
    case Plan = 'plan';
    case BypassPermissions = 'bypassPermissions';

    public function label(): string
    {
        return __('enums.permission_mode.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Default => 'gray',
            self::AcceptEdits => 'info',
            self::Plan => 'primary',
            self::BypassPermissions => 'danger',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Named permissions every action checks (see TaskAccess). Roles map to sets
 * of them today; finer-grained, configurable rights can build on the same
 * names later.
 */
enum Capability: string
{
    case TaskCreate = 'task.create';
    case TaskWrite = 'task.write';
    case TaskApprove = 'task.approve';
    case TaskShip = 'task.ship';
    case TaskReview = 'task.review';
    case TaskInvite = 'task.invite';
    case TaskTransfer = 'task.transfer';
    case TaskArchive = 'task.archive';
    case WorkspaceManage = 'workspace.manage';
    case WorkspaceSetup = 'workspace.setup';
    case WorkspaceEditor = 'workspace.editor';
    case WorkspaceOperate = 'workspace.operate';

    /** Granted to a task's owner and collaborators. */
    public function isCollaborative(): bool
    {
        return in_array($this, [self::TaskWrite, self::TaskApprove, self::TaskShip, self::TaskReview], true);
    }

    /** Granted to a task's owner only (and workspace admins). */
    public function isOwnerOnly(): bool
    {
        return in_array($this, [self::TaskInvite, self::TaskTransfer, self::TaskArchive], true);
    }
}

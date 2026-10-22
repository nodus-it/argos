<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Task;

/**
 * The task a workspace page was opened on: `/{workspace}/{task-slug}`, or
 * the older `?task=<id>` that links in notifications and comments still
 * carry. Null when it names no task of this workspace.
 */
final class RequestedTask
{
    public static function id(string $workspaceId): ?string
    {
        $wanted = request()->route('task') ?? request()->query('task');
        if (! is_string($wanted) || $wanted === '') {
            return null;
        }

        return Task::query()->where('workspace_id', $workspaceId)
            ->where(fn ($q) => $q->where('slug', $wanted)->orWhere('id', $wanted))
            ->value('id');
    }
}

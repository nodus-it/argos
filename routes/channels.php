<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

/*
| Realtime channels (Laravel Reverb). A workspace's presence channel carries
| every live update of its threads; joining it requires being able to view
| the workspace. The returned array is what other members see in presence.
*/

Broadcast::channel('workspace.{workspaceId}', function (User $user, string $workspaceId): array|false {
    $workspace = Workspace::query()->find($workspaceId);
    if ($workspace === null || ! $user->can('view', $workspace)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

Broadcast::channel('user.{userId}', fn (User $user, int $userId): bool => $user->id === $userId);

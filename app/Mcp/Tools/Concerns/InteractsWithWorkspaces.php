<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Http\Resources\Api\V1\TaskResource;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Response;

/**
 * Shared helpers for the workspace MCP tools: the signed-in user is the
 * person the run acts for; lookups only see what that user may view.
 */
trait InteractsWithWorkspaces
{
    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /** By id or exact name, among the workspaces the user may view. */
    protected function findWorkspace(string $reference): ?Workspace
    {
        $workspace = Workspace::query()->whereKey($reference)->orWhere('name', $reference)->latest()->first();

        return $workspace !== null && $this->user()->can('view', $workspace) ? $workspace : null;
    }

    protected function findTask(string $id): ?Task
    {
        $task = Task::query()->with('workspace')->find($id);

        return $task !== null && $this->user()->can('view', $task->workspace) ? $task : null;
    }

    protected function workspaceJson(Workspace $workspace): Response
    {
        $data = (new WorkspaceResource($workspace->load(['tasks' => fn ($q) => $q->whereNull('archived_at')->with(['workspace', 'owner'])])))->resolve();

        return Response::text((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    protected function taskJson(Task $task): Response
    {
        $data = (new TaskResource($task->load(['workspace', 'owner'])))->resolve();

        return Response::text((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /** Domain failures become tool errors instead of exceptions. */
    protected function attempt(Closure $action): Response
    {
        try {
            return $action();
        } catch (WorkspaceException|AuthorizationException $e) {
            return Response::error($e->getMessage());
        }
    }
}

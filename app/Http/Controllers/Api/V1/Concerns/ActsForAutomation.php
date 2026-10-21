<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AutomationActor;
use App\Services\Workspace\WorkspaceException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API endpoints act for a person (see AutomationActor), confine a
 * workspace-bound token to its workspace and answer domain failures
 * uniformly: 422 with a machine-readable `code`.
 */
trait ActsForAutomation
{
    /** The person this request acts for, or a 422 `no_actor`. */
    protected function actor(Request $request): User
    {
        return AutomationActor::for($request->user())
            ?? throw new HttpResponseException(new JsonResponse(['message' => __('workspaces.auto.no_actor'), 'code' => 'no_actor'], 422));
    }

    /** The workspace a workspace-bound token is restricted to, or null. */
    protected function scopedWorkspaceId(Request $request): ?string
    {
        $tokenable = $request->user();

        return $tokenable instanceof Workspace ? $tokenable->id : null;
    }

    /** 404 for workspaces outside the token's scope or the actor's view. */
    protected function assertWorkspaceVisible(Request $request, Workspace $workspace): void
    {
        $scoped = $this->scopedWorkspaceId($request);
        if ($scoped !== null && $scoped !== $workspace->id) {
            abort(404);
        }
        if (! $this->actor($request)->can('view', $workspace)) {
            abort(404);
        }
    }

    /** Run a domain action; WorkspaceException → 422 with `code`, policy denial → 403. */
    protected function domain(Closure $action): mixed
    {
        try {
            return $action();
        } catch (WorkspaceException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => $e->reason ?? 'workspace_error'], 422);
        } catch (AuthorizationException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'code' => 'forbidden'], 403);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\RepoProfile;
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
 * Workspace endpoints act for a person (see AutomationActor) and answer
 * domain failures uniformly: 422 with a machine-readable `code`.
 */
trait ActsForAutomation
{
    /** The person this request acts for, or a 422 `no_actor`. */
    protected function actor(Request $request): User
    {
        return AutomationActor::for($request->user())
            ?? throw new HttpResponseException(new JsonResponse(['message' => __('workspaces.auto.no_actor'), 'code' => 'no_actor'], 422));
    }

    /** 404 for workspaces the token's project scope or the actor may not see. */
    protected function assertWorkspaceVisible(Request $request, Workspace $workspace): void
    {
        $tokenable = $request->user();
        if ($tokenable instanceof RepoProfile && $tokenable->id !== $workspace->repo_profile_id) {
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

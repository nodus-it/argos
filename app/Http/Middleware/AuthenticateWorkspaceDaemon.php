<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates calls from a workspace daemon: the bearer token must be the
 * daemon token of exactly the workspace in the URL. A daemon can therefore
 * only ever act for its own workspace. Requests through the public proxy are
 * refused outright.
 */
class AuthenticateWorkspaceDaemon
{
    public const ATTRIBUTE = 'argos.workspace';

    public function handle(Request $request, Closure $next): Response
    {
        // Daemons talk to nginx directly on argos_ws. Anything that came in
        // through the public Traefik entry carries X-Forwarded-Host — those
        // requests never get to see these endpoints.
        if ($request->headers->has('X-Forwarded-Host')) {
            abort(404);
        }

        $id = (string) $request->route('workspace');
        $workspace = $id === '' ? null : Workspace::query()->find($id);
        $token = (string) $request->bearerToken();

        if ($workspace === null || $token === '' || ! hash_equals($workspace->daemon_token, $token)) {
            return response()->json(['error' => ['code' => 'unauthorized', 'message' => 'Invalid daemon token']], 401);
        }

        $request->attributes->set(self::ATTRIBUTE, $workspace);

        return $next($request);
    }
}

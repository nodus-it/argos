<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Treat every API request as a JSON client. Without this, an unauthenticated
 * request that omits `Accept: application/json` is redirected (302) to the
 * Filament login instead of getting a 401 — wrong for a REST API.
 */
class ForceJsonForApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser page loads on another host than APP_URL (e.g. `localhost` when the
 * app runs as `argos.localhost`) are redirected to the APP_URL host: the
 * session cookie is scoped to that host, so a login elsewhere would silently
 * bounce back to the login page. Machine traffic (daemon, Traefik gate,
 * health, API, webhooks, broadcasting) reaches the app on internal hosts and
 * is never touched.
 *
 * Local environment only: behind a proxy that does not forward the Host
 * header every request would look "foreign" and redirect in a loop.
 */
class RedirectToCanonicalHost
{
    private const EXEMPT = ['internal/*', '_argos/*', 'up', 'api/*', 'webhooks/*', 'broadcasting/*', 'oauth/*', 'mcp*', 'livewire*'];

    public function handle(Request $request, Closure $next): Response
    {
        $canonical = parse_url((string) config('app.url'));
        $host = strtolower((string) ($canonical['host'] ?? ''));

        if (! app()->environment('local')
            || $host === ''
            || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || ! str_contains((string) $request->header('Accept'), 'text/html')
            || strtolower($request->getHost()) === $host
            || $request->is(...self::EXEMPT)) {
            return $next($request);
        }

        $base = rtrim((string) config('app.url'), '/');

        return redirect()->away($base.$request->getRequestUri(), 302);
    }
}

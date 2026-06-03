<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * ForwardAuth target for session-protected live demos. Traefik calls this for
 * every request to such a demo, forwarding the browser's cookies plus the
 * original target in X-Forwarded-* headers.
 *
 * Authenticated → 204 (Traefik lets the request through). Otherwise the
 * original demo URL is stashed as the session `intended` URL and the user is
 * sent to the Argos login, so Filament redirects back to the demo after login.
 *
 * Cross-domain only works when the session cookie is shared between the app
 * host and the demo subdomains — set SESSION_DOMAIN to the common parent
 * (e.g. `.argos.example.com`) in production.
 */
class DemoGateController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return response()->noContent();
        }

        // Only trust a forwarded host that actually belongs to our demo domain,
        // so the stored redirect can't be turned into an open redirect.
        $base = (string) config('argos.preview.base_domain', '');
        $host = (string) $request->header('X-Forwarded-Host', '');
        if ($base !== '' && $host !== '' && str_ends_with($host, $base)) {
            $proto = (string) $request->header('X-Forwarded-Proto', 'https');
            $uri = (string) $request->header('X-Forwarded-Uri', '/');
            $request->session()->put('url.intended', $proto.'://'.$host.$uri);
        }

        return redirect()->to(route('filament.admin.auth.login'));
    }
}

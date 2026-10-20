<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAccessTokens;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * ForwardAuth target for workspace hosts: `code-<slug>.<base>` (the editor —
 * effectively a shell into the container) and `demo-ws-<slug>.<base>` (the
 * workspace preview). Unlike DemoGateController it checks *membership*, not
 * just a login: only users who may view the workspace get through.
 *
 * Stateless (no session): access is the host-only cookie, or a fresh ticket
 * from the workspace page that is traded for the cookie with a redirect.
 * Both work whether or not the hosts share the app's domain. Decisions are
 * cached briefly — the editor fires hundreds of requests while loading.
 */
class WorkspaceGateController extends Controller
{
    private const DECISION_TTL = 30;

    public function __invoke(Request $request, WorkspaceAccessTokens $tokens, WorkspaceService $workspaces): Response
    {
        $workspace = $this->workspaceFromHost($request);
        if ($workspace === null) {
            return response('', 404);
        }

        $cookie = (string) $request->cookies->get($tokens->cookieName($workspace), '');
        if ($cookie !== '') {
            $cacheKey = 'ws-gate:'.hash('sha256', $cookie);
            $allowed = Cache::remember($cacheKey, self::DECISION_TTL, function () use ($tokens, $workspace, $cookie, $workspaces): bool {
                $userId = $tokens->verifyCookie($workspace, $cookie);
                $user = $userId !== null ? User::query()->find($userId) : null;

                return $user !== null && $this->decide($workspace, $user, $workspaces);
            });
            if ($allowed) {
                return response()->noContent();
            }
        }

        [$path, $ticket] = $this->ticketFromUri((string) $request->header('X-Forwarded-Uri', '/'));
        if ($ticket !== null) {
            $userId = $tokens->verifyTicket($workspace, $ticket);
            $user = $userId !== null ? User::query()->find($userId) : null;
            if ($user !== null && $this->decide($workspace, $user, $workspaces)) {
                return $this->grant($request, $workspace, $user, $tokens, $path);
            }
        }

        return $this->deny($request);
    }

    private function decide(Workspace $workspace, User $user, WorkspaceService $workspaces): bool
    {
        if (! $user->can('view', $workspace)) {
            return false;
        }
        $workspaces->touch($workspace);

        return true;
    }

    /** Trade the ticket for the access cookie and drop the ticket from the URL. */
    private function grant(Request $request, Workspace $workspace, User $user, WorkspaceAccessTokens $tokens, string $path): Response
    {
        $secure = $request->header('X-Forwarded-Proto', 'http') === 'https';
        $host = (string) $request->header('X-Forwarded-Host');

        return redirect()->away(($secure ? 'https' : 'http').'://'.$host.$path)->withCookie(new Cookie(
            $tokens->cookieName($workspace),
            $tokens->cookie($workspace, $user),
            now()->getTimestamp() + $tokens->cookieLifetimeSeconds(),
            '/',
            null,
            $secure,
            true,
            false,
            // Inside the Argos iframe the editor host is a third party on
            // non-matching domains; that only works with SameSite=None+Secure.
            $secure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
        ));
    }

    private function deny(Request $request): Response
    {
        // Top-level navigation: send the person to the Argos login and back.
        if (str_contains((string) $request->header('Accept', ''), 'text/html')) {
            $loginUrl = rtrim((string) config('app.url'), '/').route('filament.admin.auth.login', [], false);

            return redirect()->away($loginUrl);
        }

        return response('', 401);
    }

    /** Workspace for `code-<slug>.<base>` / `demo-ws-<slug>.<base>`, else null. */
    private function workspaceFromHost(Request $request): ?Workspace
    {
        $base = (string) config('argos.preview.base_domain', '');
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) $request->header('X-Forwarded-Host', '')));
        if ($base === '' || $host === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $label = substr($host, 0, -strlen('.'.$base));
        $slug = match (true) {
            str_starts_with($label, 'code-') => substr($label, 5),
            str_starts_with($label, 'demo-ws-') => substr($label, 8),
            default => null,
        };
        if ($slug === null || $slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return null;
        }

        return Workspace::query()->where('slug', $slug)->first();
    }

    /**
     * Split the ticket off the forwarded URI.
     *
     * @return array{0: string, 1: string|null}
     */
    private function ticketFromUri(string $uri): array
    {
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);
        $ticket = $query[WorkspaceAccessTokens::TICKET_PARAM] ?? null;
        unset($query[WorkspaceAccessTokens::TICKET_PARAM]);
        $rest = http_build_query($query);

        return [$path.($rest !== '' ? '?'.$rest : ''), is_string($ticket) ? $ticket : null];
    }
}

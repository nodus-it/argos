<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Demo;
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
 * effectively a shell into the container) and `demo-<workspace>-<task>.<base>` (the
 * workspace preview). Unlike DemoGateController it checks *membership*, not
 * just a login: the editor needs an operating role (owner/editor), the
 * preview any membership.
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
        [$workspace, $ability] = $this->targetFromHost($request);
        if ($workspace === null) {
            return response('', 404);
        }

        $cookie = (string) $request->cookies->get($tokens->cookieName($workspace), '');
        if ($cookie !== '') {
            $cacheKey = 'ws-gate:'.$ability.':'.hash('sha256', $cookie);
            $allowed = Cache::remember($cacheKey, self::DECISION_TTL, function () use ($tokens, $workspace, $cookie, $workspaces, $ability): bool {
                $userId = $tokens->verifyCookie($workspace, $cookie);
                $user = $userId !== null ? User::query()->find($userId) : null;

                return $user !== null && $this->decide($workspace, $user, $ability, $workspaces);
            });
            if ($allowed) {
                return response()->noContent();
            }
        }

        [$path, $ticket] = $this->ticketFromUri((string) $request->header('X-Forwarded-Uri', '/'));
        if ($ticket !== null) {
            $userId = $tokens->verifyTicket($workspace, $ticket);
            $user = $userId !== null ? User::query()->find($userId) : null;
            if ($user !== null && $this->decide($workspace, $user, $ability, $workspaces)) {
                return $this->grant($request, $workspace, $user, $tokens, $path);
            }
        }

        return $this->deny($request);
    }

    private function decide(Workspace $workspace, User $user, string $ability, WorkspaceService $workspaces): bool
    {
        if (! $user->can($ability, $workspace)) {
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
            // Requests from Argos pages to a workspace host on a different
            // registrable domain are cross-site; with TLS allow them
            // (SameSite=None needs Secure), otherwise fall back to Lax.
            $secure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
        ));
    }

    private function deny(Request $request): Response
    {
        // Argos no longer frames workspace hosts, but a host embedded
        // elsewhere must not render the Argos login inside a frame.
        if ($request->header('Sec-Fetch-Dest') === 'iframe') {
            return response('', 401);
        }

        // Top-level navigation: send the person to the Argos login and back.
        if (str_contains((string) $request->header('Accept', ''), 'text/html')) {
            $loginUrl = rtrim((string) config('app.url'), '/').route('filament.admin.auth.login', [], false);

            return redirect()->away($loginUrl);
        }

        return response('', 401);
    }

    /**
     * Workspace and required ability for the forwarded host: the editor
     * (`code-<slug>`) is a shell into the container and needs the editor
     * capability; a task preview (`demo-<workspace>-<task>`, see
     * Task::demoSlugFor()) is readable by every member.
     *
     * @return array{0: Workspace|null, 1: string}
     */
    private function targetFromHost(Request $request): array
    {
        $base = (string) config('argos.preview.base_domain', '');
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) $request->header('X-Forwarded-Host', '')));
        if ($base === '' || $host === '' || ! str_ends_with($host, '.'.$base)) {
            return [null, 'view'];
        }

        $label = substr($host, 0, -strlen('.'.$base));
        if (preg_match('/^[a-z0-9-]+$/', $label) !== 1) {
            return [null, 'view'];
        }

        if (str_starts_with($label, 'code-')) {
            return [Workspace::query()->where('slug', substr($label, 5))->first(), 'editor'];
        }

        if (str_starts_with($label, 'demo-')) {
            $demo = Demo::query()->where('compose_project', $label)->latest()->first();

            return [$demo?->workspace, 'view'];
        }

        return [null, 'view'];
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

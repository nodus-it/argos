<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;

/**
 * Signed access tokens for workspace hosts (code-<slug>, demo-ws-<slug>).
 *
 * The Argos session cookie only reaches those hosts when they share the
 * app's registrable domain. Everywhere else (local nip.io setups) the page
 * hands the browser a short-lived *ticket*; the workspace gate trades it
 * for a host-only *access cookie*. Both are HMACs over workspace, user and
 * expiry — membership is still re-checked on every request.
 */
class WorkspaceAccessTokens
{
    public const TICKET_PARAM = 'argos_ticket';

    private const TICKET_TTL = 120;

    public function cookieName(Workspace $workspace): string
    {
        return 'argos_ws_'.str_replace('-', '_', $workspace->slug);
    }

    public function ticket(Workspace $workspace, User $user): string
    {
        return $this->sign('ticket', $workspace->id, $user->id, now()->getTimestamp() + self::TICKET_TTL);
    }

    public function cookie(Workspace $workspace, User $user): string
    {
        $hours = max(1, (int) config('argos.workspaces.editor_cookie_hours', 12));

        return $this->sign('cookie', $workspace->id, $user->id, now()->getTimestamp() + $hours * 3600);
    }

    public function cookieLifetimeSeconds(): int
    {
        return max(1, (int) config('argos.workspaces.editor_cookie_hours', 12)) * 3600;
    }

    /** User id of a valid ticket for this workspace, else null. */
    public function verifyTicket(Workspace $workspace, string $token): ?int
    {
        return $this->verify('ticket', $workspace, $token);
    }

    /** User id of a valid access cookie for this workspace, else null. */
    public function verifyCookie(Workspace $workspace, string $token): ?int
    {
        return $this->verify('cookie', $workspace, $token);
    }

    private function sign(string $purpose, string $workspaceId, int $userId, int $expires): string
    {
        $payload = $workspaceId.'.'.$userId.'.'.$expires;

        return $payload.'.'.hash_hmac('sha256', $purpose.'|'.$payload, $this->key());
    }

    private function verify(string $purpose, Workspace $workspace, string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4) {
            return null;
        }
        [$workspaceId, $userId, $expires, $mac] = $parts;
        $expected = hash_hmac('sha256', $purpose.'|'.$workspaceId.'.'.$userId.'.'.$expires, $this->key());

        if (! hash_equals($expected, $mac) || $workspaceId !== $workspace->id || ! ctype_digit($userId) || (int) $expires < now()->getTimestamp()) {
            return null;
        }

        return (int) $userId;
    }

    private function key(): string
    {
        return 'argos-workspace-access|'.(string) config('app.key');
    }
}

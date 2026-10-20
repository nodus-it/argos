<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Demo\TraefikRouter;
use App\Services\Workspace\WorkspaceAccessTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redirects to a workspace host (`code` → editor, `preview` → workspace
 * demo) with a freshly minted access ticket, so the iframe and the
 * "open in new tab" link never carry an expired one.
 */
class WorkspaceOpenController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, string $target, WorkspaceAccessTokens $tokens, TraefikRouter $router): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $workspace), 404);

        $host = $target === 'code' ? TraefikRouter::codeKey($workspace->slug) : 'demo-'.$workspace->demoKey();
        $url = $router->urlForSlug($host).'/';
        if ($target === 'code') {
            $url .= '?folder=/workspace&';
        } else {
            $url .= '?';
        }

        return redirect()->away($url.WorkspaceAccessTokens::TICKET_PARAM.'='.urlencode($tokens->ticket($workspace, $user)));
    }
}

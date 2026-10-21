<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Demo\TraefikRouter;
use App\Services\Workspace\WorkspaceAccessTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Redirects to a workspace host (`code` → editor, `preview` → workspace
 * demo) with a freshly minted access ticket, so the links in the workspace
 * (opened in their own tab) never carry an expired one.
 */
class WorkspaceOpenController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, string $target, WorkspaceAccessTokens $tokens, TraefikRouter $router): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $workspace), 404);
        // The editor is a shell into the container: readers get the preview only.
        abort_if($target === 'code' && ! $user->can('editor', $workspace), 403);

        if ($target === 'code') {
            $host = TraefikRouter::codeKey($workspace->slug);
        } else {
            $task = Task::query()->where('workspace_id', $workspace->id)->find((string) $request->query('task'));
            abort_if($task === null, 404);
            $host = $task->demoSlug();
        }
        $url = $router->urlForSlug($host).'/';
        if ($target === 'code') {
            $url .= '?folder=/workspace&';
        } else {
            $url .= '?';
        }

        return redirect()->away($url.WorkspaceAccessTokens::TICKET_PARAM.'='.urlencode($tokens->ticket($workspace, $user)));
    }
}

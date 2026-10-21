<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\BitbucketConnectedAccountController;
use App\Http\Controllers\Auth\ConnectedAccountController;
use App\Http\Controllers\Auth\LinearConnectedAccountController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\ThreadAttachmentController;
use App\Http\Controllers\Webhooks\IssueWebhookController;
use App\Http\Controllers\WorkspaceGateController;
use App\Http\Controllers\WorkspaceOpenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Workspaces are the everyday surface; administration lives under /admin.
Route::get('/', fn () => redirect(config('argos.workspaces.enabled', true) ? '/w' : '/admin'));

// Workspaces moved from the admin panel to their own panel: keep old links
// (notifications, pull request bodies) working.
Route::get('/admin/workspaces/{path?}', fn (Request $request, ?string $path = null) => redirect(
    '/w/workspaces'.($path !== null ? '/'.$path : '').($request->getQueryString() !== null ? '?'.$request->getQueryString() : ''),
    301,
))
    ->where('path', '.*');

// Traefik forwardAuth target for workspace hosts (code-<slug> editor and
// workspace demos). Checks membership, not just a login. See
// WorkspaceGateController.
// Stateless on purpose: forwardAuth runs for every editor request, and the
// web group would start a throwaway session each time (Traefik drops the
// cookie on success). Access comes from the ticket/host cookie instead.
Route::get('/_argos/workspace-gate', WorkspaceGateController::class)
    ->name('workspace.gate')
    ->withoutMiddleware(['web']);

// Inbound issue webhooks — no session auth, signature verified in controller.
// SubstituteBindings is re-added explicitly since withoutMiddleware('web') would
// remove it together with CSRF, which would break route-model binding for {binding}.
Route::post('/webhooks/issues/{kind}/{binding}', [IssueWebhookController::class, 'handle'])
    ->name('webhooks.issues')
    ->withoutMiddleware(['web'])
    ->middleware(['throttle:60,1']);

Route::middleware('auth')->group(function () {
    Route::get('/threads/{thread}/attachments/{file}', ThreadAttachmentController::class)
        ->name('threads.attachments');

    // Open a workspace host (editor/preview) with a fresh access ticket.
    Route::get('/workspaces/{workspace}/open/{target}', WorkspaceOpenController::class)
        ->whereIn('target', ['code', 'preview'])
        ->name('workspaces.open');
    Route::get('/system/log/download', SystemLogController::class)
        ->name('system.log.download');
});

Route::middleware('auth')->group(function () {
    Route::get('/auth/github/redirect', [ConnectedAccountController::class, 'redirect'])
        ->name('auth.github.redirect');

    Route::get('/auth/github/callback', [ConnectedAccountController::class, 'callback'])
        ->name('auth.github.callback');

    Route::post('/auth/github/disconnect', [ConnectedAccountController::class, 'disconnect'])
        ->name('auth.github.disconnect');

    Route::get('/auth/gitlab/redirect', [ConnectedAccountController::class, 'redirectGitlab'])
        ->name('auth.gitlab.redirect');

    Route::get('/auth/gitlab/callback', [ConnectedAccountController::class, 'callbackGitlab'])
        ->name('auth.gitlab.callback');

    Route::post('/auth/gitlab/disconnect', [ConnectedAccountController::class, 'disconnectGitlab'])
        ->name('auth.gitlab.disconnect');

    Route::get('/auth/bitbucket/redirect', [BitbucketConnectedAccountController::class, 'redirect'])
        ->name('auth.bitbucket.redirect');

    Route::get('/auth/bitbucket/callback', [BitbucketConnectedAccountController::class, 'callback'])
        ->name('auth.bitbucket.callback');

    Route::post('/auth/bitbucket/disconnect', [BitbucketConnectedAccountController::class, 'disconnect'])
        ->name('auth.bitbucket.disconnect');

    Route::get('/auth/linear/redirect', [LinearConnectedAccountController::class, 'redirect'])
        ->name('auth.linear.redirect');

    Route::get('/auth/linear/callback', [LinearConnectedAccountController::class, 'callback'])
        ->name('auth.linear.callback');

    Route::post('/auth/linear/disconnect', [LinearConnectedAccountController::class, 'disconnect'])
        ->name('auth.linear.disconnect');
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\BitbucketConnectedAccountController;
use App\Http\Controllers\Auth\ConnectedAccountController;
use App\Http\Controllers\Auth\LinearConnectedAccountController;
use App\Http\Controllers\LegacyAdminRedirectController;
use App\Http\Controllers\LegacyWorkspaceRedirectController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\UserAvatarController;
use App\Http\Controllers\Webhooks\IssueWebhookController;
use App\Http\Controllers\WorkspaceGateController;
use App\Http\Controllers\WorkspaceOpenController;
use Illuminate\Support\Facades\Route;

// The admin panel is gone: its pages live under /account, /system, /docs
// and /setup now.
Route::get('/admin/{path?}', LegacyAdminRedirectController::class)
    ->where('path', '.*')
    ->name('admin.legacy');

// The workspace app sits at the root (`/`, `/{workspace}/{task}`); links
// from before still point at /w/… — they keep working.
Route::middleware('auth')->group(function () {
    Route::get('/w/{path?}', LegacyWorkspaceRedirectController::class)
        ->where('path', '.*')
        ->name('workspaces.legacy');
});

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
    Route::get('/tasks/{task}/attachments/{file}', TaskAttachmentController::class)
        ->name('tasks.attachments');

    Route::get('/users/{user}/avatar', UserAvatarController::class)
        ->name('users.avatar');

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

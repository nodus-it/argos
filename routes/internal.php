<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\RepoCredentialController;
use App\Http\Middleware\AuthenticateWorkspaceDaemon;
use Illuminate\Support\Facades\Route;

/*
| Internal endpoints for workspace daemons (argos_ws network). Stateless: no
| session, no CSRF. Every route authenticates with the workspace's daemon
| token and is rate-limited per workspace.
*/

Route::middleware([AuthenticateWorkspaceDaemon::class, 'throttle:workspace-daemon'])
    ->prefix('internal/workspaces/{workspace}')
    ->group(function (): void {
        Route::get('repo-credential', RepoCredentialController::class)->name('internal.workspaces.repo-credential');
    });

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ThreadController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 — a server-to-server channel onto the same workspace logic as
| the UI and the MCP server. Auth: Sanctum bearer tokens with abilities. Tokens
| are bound either to a User (full access) or a RepoProfile (project-scoped).
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::middleware('abilities:workspaces:read')->group(function (): void {
        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
        Route::get('threads/{thread}', [ThreadController::class, 'show']);
    });

    Route::middleware('abilities:workspaces:write')->group(function (): void {
        Route::post('workspaces', [WorkspaceController::class, 'store']);
        Route::post('workspaces/{workspace}/threads', [ThreadController::class, 'store']);
        Route::post('workspaces/{workspace}/ship', [WorkspaceController::class, 'ship']);
        Route::post('threads/{thread}/messages', [ThreadController::class, 'message']);
        Route::post('threads/{thread}/plan', [ThreadController::class, 'plan']);
        Route::post('threads/{thread}/pause', [ThreadController::class, 'pause']);
        Route::post('threads/{thread}/resume', [ThreadController::class, 'resume']);
    });

    Route::middleware('abilities:projects:read')->group(function (): void {
        Route::get('projects', [ProjectController::class, 'index']);
        Route::get('projects/{repoProfile}', [ProjectController::class, 'show']);
    });
});

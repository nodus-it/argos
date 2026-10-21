<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 — a server-to-server channel onto the same workspace logic as
| the UI and the MCP server. Auth: Sanctum bearer tokens with abilities. Tokens
| are bound either to an API client (acts as its user) or a workspace
| (workspace-scoped, acts as its automation user).
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::middleware('abilities:workspaces:read')->group(function (): void {
        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
    });

    Route::middleware('abilities:tasks:read')->group(function (): void {
        Route::get('workspaces/{workspace}/tasks', [TaskController::class, 'index']);
        Route::get('tasks/{task}', [TaskController::class, 'show']);
    });

    Route::middleware('abilities:tasks:write')->group(function (): void {
        Route::post('workspaces/{workspace}/tasks', [TaskController::class, 'store']);
        Route::post('tasks/{task}/messages', [TaskController::class, 'message']);
        Route::post('tasks/{task}/plan', [TaskController::class, 'plan']);
        Route::post('tasks/{task}/pause', [TaskController::class, 'pause']);
        Route::post('tasks/{task}/resume', [TaskController::class, 'resume']);
        Route::post('tasks/{task}/ship', [TaskController::class, 'ship']);
    });
});

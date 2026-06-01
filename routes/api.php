<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 — a server-to-server channel onto the same TaskService logic as
| the UI and the MCP server. Auth: Sanctum bearer tokens with abilities. Tokens
| are bound either to a User (full access) or a RepoProfile (project-scoped).
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::middleware('abilities:projects:read')->group(function (): void {
        Route::get('projects', [ProjectController::class, 'index']);
        Route::get('projects/{repoProfile}', [ProjectController::class, 'show']);
    });

    Route::middleware('abilities:tasks:read')->group(function (): void {
        Route::get('tasks', [TaskController::class, 'index']);
        Route::get('tasks/{task}', [TaskController::class, 'show']);
    });

    Route::middleware('abilities:tasks:write')->group(function (): void {
        Route::post('tasks', [TaskController::class, 'store']);
        Route::post('tasks/{task}/feedback', [TaskController::class, 'feedback']);
        Route::post('tasks/{task}/concept', [TaskController::class, 'concept']);
        Route::post('tasks/{task}/implement', [TaskController::class, 'implement']);
        Route::post('tasks/{task}/pr', [TaskController::class, 'pr']);
    });
});

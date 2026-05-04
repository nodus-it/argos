<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\BitbucketConnectedAccountController;
use App\Http\Controllers\Auth\ConnectedAccountController;
use App\Http\Controllers\TaskLogController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

Route::middleware('auth')->group(function () {
    Route::get('/tasks/{task}/logs/download', [TaskLogController::class, 'downloadPhaseLog'])
        ->name('tasks.logs.download');

    Route::get('/system/log/download', [TaskLogController::class, 'downloadAppLog'])
        ->name('system.log.download');
});

Route::middleware('auth')->group(function () {
    Route::get('/auth/github/redirect', [ConnectedAccountController::class, 'redirect'])
        ->name('auth.github.redirect');

    Route::get('/auth/github/callback', [ConnectedAccountController::class, 'callback'])
        ->name('auth.github.callback');

    Route::post('/auth/github/disconnect', [ConnectedAccountController::class, 'disconnect'])
        ->name('auth.github.disconnect');

    Route::get('/auth/bitbucket/redirect', [BitbucketConnectedAccountController::class, 'redirect'])
        ->name('auth.bitbucket.redirect');

    Route::get('/auth/bitbucket/callback', [BitbucketConnectedAccountController::class, 'callback'])
        ->name('auth.bitbucket.callback');

    Route::post('/auth/bitbucket/disconnect', [BitbucketConnectedAccountController::class, 'disconnect'])
        ->name('auth.bitbucket.disconnect');
});

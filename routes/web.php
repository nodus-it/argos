<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ConnectedAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/auth/github/redirect', [ConnectedAccountController::class, 'redirect'])
        ->name('auth.github.redirect');

    Route::get('/auth/github/callback', [ConnectedAccountController::class, 'callback'])
        ->name('auth.github.callback');

    Route::post('/auth/github/disconnect', [ConnectedAccountController::class, 'disconnect'])
        ->name('auth.github.disconnect');
});

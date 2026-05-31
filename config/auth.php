<?php

use App\Models\User;

// Single-user-model app. The session `web` guard drives Filament; the
// Passport-backed `api` guard authenticates the MCP server (scope `mcp:use`).

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'passport',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'password_timeout' => 10800,

];

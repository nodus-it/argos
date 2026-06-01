<?php

use App\Models\User;

// Single-user-model app. The session `web` guard drives Filament; the
// Passport-backed `api` guard authenticates the MCP server (scope `mcp:use`);
// the Sanctum `sanctum` guard authenticates the REST API (bearer tokens with
// abilities, bound to a User or a RepoProfile).

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

        // No 'provider' on purpose: Sanctum's hasValidProvider() would otherwise
        // reject any tokenable that isn't the provider's model. Our tokens bind
        // to ApiClient (full access) or RepoProfile (project-scoped), never User,
        // so the guard must accept any HasApiTokens model.
        'sanctum' => [
            'driver' => 'sanctum',
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

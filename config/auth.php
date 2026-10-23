<?php

use App\Models\User;

// Single-user-model app. The session `web` guard drives Filament; the
// Passport-backed `api` guard authenticates the MCP server (scope `mcp:use`);
// the Sanctum `sanctum` guard authenticates the REST API (bearer tokens with
// abilities, bound to a User or a Workspace).

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
        // to ApiClient (full access) or Workspace (workspace-scoped), never User,
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

    // Access links (set or reset a password) come from an administrator, not
    // from mail: they must survive until the colleague opens them.
    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => max(1, (int) env('ARGOS_ACCESS_LINK_HOURS', 72)) * 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];

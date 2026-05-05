<?php

// Closed-deployment app — only OAuth credentials are ENV-driven.
// Callback paths are fixed; Socialite resolves the relative path against APP_URL.

return [

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => '/auth/github/callback',
    ],

    'gitlab' => [
        'client_id' => env('GITLAB_CLIENT_ID'),
        'client_secret' => env('GITLAB_CLIENT_SECRET'),
        'redirect' => '/auth/gitlab/callback',
        // socialiteproviders/gitlab concatenates this verbatim with 'oauth/authorize',
        // so we MUST guarantee a trailing slash. rtrim+'/' is idempotent on either form.
        'instance_uri' => rtrim((string) env('GITLAB_INSTANCE_URL', 'https://gitlab.com'), '/').'/',
    ],

    'bitbucket' => [
        'client_id' => env('BITBUCKET_CLIENT_ID'),
        'client_secret' => env('BITBUCKET_CLIENT_SECRET'),
        'redirect' => '/auth/bitbucket/callback',
    ],

];
